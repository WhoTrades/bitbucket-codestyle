<?php
/**
 * @author Artem Naumenko
 *
 * Обработчик запросов на изменение веток
 */
namespace PhpCsBitBucket;

use PhpCsBitBucket\Checker\CheckerInterface;
use PhpCsBitBucket\CheckerResult\CheckerResultItemInterface;
use PhpCsBitBucket\Exception\BitBucketJsonFailure;
use PhpCsBitBucket\Exception\BitBucketFileInConflict;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Monolog\Logger;
use PhpCsBitBucket\BitBacket\Collection\Blame as BlameCollection;
use PhpCsBitBucket\BitBacket\Exception\LineNotExistException;
use PhpCsBitBucket\BitBacket\Exception\BlameDuplicateLineException;

/**
 * Class RequestProcessor
 * @package PhpCsBitBucket
 */
class RequestProcessor
{
    /**
     * @var BitBucketApi
     */
    private $bitBucket;

    /**
     * @var Logger
     */
    private $log;

    /**
     * @var CheckerInterface
     */
    private $checker;

    /**
     * @param BitBucketApi           $bucket
     * @param Logger             $log
     * @param CheckerInterface   $checker
     */
    public function __construct(
        Logger $log,
        BitBucketApi $bucket,
        CheckerInterface $checker
    ) {
        $this->log = $log;
        $this->bitBucket = $bucket;
        $this->checker = $checker;
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param string $ref
     *
     * @return array
     */
    public function processRequest($slug, $repo, $ref)
    {
        $this->log->info("Processing request with slug=$slug, repo=$repo, ref=$ref");

        $pullRequests = $this->bitBucket->getPullRequestsByBranch($slug, $repo, $ref);
        $this->log->info("Found {$pullRequests['size']} pull requests");
        $result = [];
        foreach ($pullRequests['values'] as $pullRequest) {
            $result = array_merge($result, $this->processPullRequest($slug, $repo, $pullRequest));
        }

        return $result;
    }

    protected function processPullRequest($slug, $repo, array $pullRequest)
    {
        $this->log->info(
            "Processing pull request #{$pullRequest['id']} {$pullRequest['fromRef']['latestCommit']}..{$pullRequest['toRef']['latestCommit']}"
        );

        $result = [];

        try {
            if ($this->bitBucket->getUserName() != $pullRequest['author']['user']['name']) {
                $this->bitBucket->addMeToPullRequestReviewers($slug, $repo, $pullRequest['id']);
            }

            $changes = $this->bitBucket->getPullRequestDiffs($slug, $repo, $pullRequest['id'], 0);

            foreach ($changes['diffs'] as $diff) {
                // файл был удален, нечего проверять
                if ($diff['destination'] === null) {
                    $this->log->info("Skip processing {$diff['source']['toString']}, as it was removed");
                    continue;
                }
                $filename = $diff['destination']['toString'];
                if ($errors = $this->getDiffErrors($slug, $repo, $diff, $filename, $pullRequest['id'])) {
                    $affectedLines = $this->getDiffAffectedLines($diff);
                    try {
                        $blameCollection = $this->bitBucket->getFileBlame($slug, $repo, $filename, $pullRequest['fromRef']['latestCommit']);
                        $affectedLines = $this->filterOutAffectedLinesFormMergedCommits($slug, $repo, $affectedLines, $blameCollection, $pullRequest['fromRef']['id']);
                    } catch (BlameDuplicateLineException $e) {
                        $this->log->warning("Skip filtering out merged commits, reason={$e->getMessage()}");
                    }
                    $errors = $this->filterErrorsByAffectedLines($errors, $affectedLines);
                }

                $comments = $this->getComments($errors);

                $result[$filename] = array_merge($result[$filename] ?? [], $errors);

                $this->log->info("Summary errors count after filtration: " . count($comments));

                $this->actualizePullRequestComments($slug, $repo, $pullRequest['id'], $filename, $comments);
            }

            $this->removeOutdatedRobotComments($slug, $repo, $pullRequest['id']);

            $this->markPullRequestMark($slug, $repo, $pullRequest['id'], $result);
        } catch (ClientException $e) {
            $this->log->critical("Error integration with bitbucket: " . $e->getMessage(), [
                'type' => 'client',
                'reply' => (string) $e->getResponse()->getBody(),
                'headers' => $e->getResponse()->getHeaders(),
            ]);
        } catch (ServerException $e) {
            $this->log->critical("Error integration with bitbucket: " . $e->getMessage(), [
                'type' => 'server',
                'reply' => (string) $e->getResponse()->getBody(),
                'headers' => $e->getResponse()->getHeaders(),
            ]);
        } catch (BitBucketJsonFailure $e) {
            $this->log->error("Json failure at pull request #{$pullRequest['id']}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int $pullRequestId
     * @param CheckerResultItemInterface[] $errors
     *
     * @return bool
     */
    protected function markPullRequestMark(string $slug, string $repo, int $pullRequestId, array $errors)
    {
        foreach ($errors as $list) {
            foreach ($list as $error) {
                /** @var $error CheckerResultItemInterface */
                if ($error->isError()) {
                    $this->bitBucket->unapprovePullRequest($slug, $repo, $pullRequestId);
                    $this->log->info("Unapprove pull request #$pullRequestId");

                    return false;
                }
            }
        }

        $this->bitBucket->approvePullRequest($slug, $repo, $pullRequestId);
        $this->log->info("Approved pull request #$pullRequestId");

        return true;
    }

    protected function getDiffErrors($slug, $repo, $diff, $filename, $pullRequestId)
    {
        $extension = $diff['destination']['extension'] ?? null;
        $this->log->info("Processing file $filename");

        if ($this->checker->shouldIgnoreFile($filename, $extension)) {
            $this->log->info("File is in ignore list, so no errors can be found");

            return [];
        }

        try {
            $fileContent = $this->bitBucket->getFileContent($slug, $repo, $pullRequestId, $filename);
        } catch (BitBucketFileInConflict $e) {
            $this->log->error("File $filename at pull request #$pullRequestId os in conflict state, skip code style checking");

            return [];
        } catch (BitBucketJsonFailure $e) {
            $this->log->error("Can't get contents of $filename at pull request #$pullRequestId");

            return [];
        }

        $this->log->debug("File content length: ".mb_strlen($fileContent));

        $errors = $this->checker->processFile($filename, $extension, $fileContent);
        $this->log->info("Summary errors count: ".count($errors));

        return $errors;
    }

    /**
     * @param $diff
     * @return int[] $affectedLines - list of affected lines [12, 13, 144, 145, 146]
     */
    protected function getDiffAffectedLines($diff)
    {
        $affectedLines = [0];

        if (!empty($diff['hunks'])) {
            foreach ($diff['hunks'] as $hunk) {
                foreach ($hunk['segments'] as $segment) {
                    if ($segment['type'] == 'CONTEXT' || $segment['type'] == 'REMOVED') {
                        continue;
                    }
                    foreach ($segment['lines'] as $line) {
                        $affectedLines[] = $line['destination'];
                    }
                }
            }
        }

        $this->log->info("Affected lines: " . $this->visualizeNumbersToInterval(array_keys($affectedLines)));

        return $affectedLines;
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param array $affectedLines
     * @param BlameCollection $blameCollection
     *
     * @return array
     */
    protected function filterOutAffectedLinesFormMergedCommits($slug, $repo, array $affectedLines, BlameCollection $blameCollection, $ref)
    {
        $branchTicket = substr($ref, strrpos($ref, '/') + 1);

        $affectedLinesResult = [];
        foreach ($affectedLines as $affectedLineNumber) {
            // ag: Keep checking of whole file
            if ($affectedLineNumber === 0) {
                $affectedLinesResult[0] = 0;

                continue;
            }
            try {
                $blame = $blameCollection->getBlameByLineNumber($affectedLineNumber);
            } catch (LineNotExistException $e) {
                $this->log->warning("Keep not checked line because can't get blame for line {$affectedLineNumber}, reason={$e->getMessage()}");
                $affectedLinesResult[] = $affectedLineNumber;

                continue;
            }

            $commit = $this->bitBucket->getCommitById($slug, $repo, $blame->commitId);

            // ag: Skip explicitly tagged lines of another tickets
            if (preg_match('/\#([\w\-]+)$/', $commit->message, $match) && $match[1] !== $branchTicket) {
                continue;
            }

            $affectedLinesResult[] = $affectedLineNumber;
        }

        $this->log->info("Affected lines after sort out merged commits: " . $this->visualizeNumbersToInterval(array_keys($affectedLinesResult)));

        return $affectedLinesResult;
    }

    /**
     * @param CheckerResultItemInterface[] $errors
     * @param int[] $affectedLines - list of affected lines [12, 13, 144, 145, 146]
     *
     * @return CheckerResultItemInterface[]
     */
    protected function filterErrorsByAffectedLines(array $errors, array $affectedLines)
    {
        $result = array_filter($errors, function (CheckerResultItemInterface $error) use ($affectedLines) {
            $line = $error->getAffectedLine();

            return in_array($line, $affectedLines);
        });

        return $result;
    }

    /**
     * @param CheckerResultItemInterface[] $errors
     *
     * @return array list of comments by file line [ 12 => 'Error at line 12', 144 => 'Othr error at line 144']
     */
    protected function getComments(array $errors)
    {
        $comments = [];
        foreach ($errors as $error) {
            /** @var $error CheckerResultItemInterface */
            $line = $error->getAffectedLine();

            if (!isset($comments[$line])) {
                $comments[$line] = [];
            }

            $comments[$line][] = $error->getMessage() . "\n";
        }

        $comments = array_map(function ($val) {
            return implode("\n", array_unique($val));
        }, $comments);

        return $comments;
    }

    protected function actualizePullRequestComments($slug, $repo, $pullRequestId, $filename, $comments)
    {
        $existingComments = $this->bitBucket->getPullRequestComments(
            $slug,
            $repo,
            $pullRequestId,
            $filename
        )['values'];

        $this->log->info("Found ".count($existingComments)." comment at this pull request");

        foreach ($existingComments as $comment) {
            // filtering only robot comments
            if ($comment['author']['name'] != $this->bitBucket->getUserName()) {
                continue;
            }

            $line = $comment['anchor']['line'] ?? null;
            if (!isset($comments[$line])) {
                // Comment exist at remote and not exists now, so remove it
                $this->log->info("Deleting comment #{$comment['id']}", [
                    'line' => $comment['anchor']['line'],
                    'file' => $filename,
                ]);

                if (empty($comment['comments'])) {
                    $this->bitBucket->deletePullRequestComment(
                        $slug,
                        $repo,
                        $pullRequestId,
                        $comment['version'],
                        $comment['id']
                    );
                } else {
                    //If there are replies to our comment - just strike through our message
                    //@see https://confluence.atlassian.com/display/STASH0310/Markdown+syntax+guide#Markdownsyntaxguide-Characterstyles
                    $this->bitBucket->updatePullRequestComment(
                        $slug,
                        $repo,
                        $pullRequestId,
                        $comment['id'],
                        $comment['version'],
                        $this->getStrikeThroughedCommentText($comment["text"])
                    );
                }

            } elseif (trim($comment['text']) != trim($comments[$comment['anchor']['line']])) {
                // Comment exist at remote and exists now, but text are different - so modify remote text
                $this->log->info("Updating comment #{$comment['id']}", [
                    'line' => $comment['anchor']['line'],
                    'file' => $filename,
                    'newText' => $comments[$comment['anchor']['line']],
                    'oldText' => $comment['text'],
                ]);

                $this->bitBucket->updatePullRequestComment(
                    $slug,
                    $repo,
                    $pullRequestId,
                    $comment['id'],
                    $comment['version'],
                    $comments[$comment['anchor']['line']]
                );
            }

            unset($comments[$comment['anchor']['line']]);
        }

        // all rest comments just adding to pull request
        foreach ($comments as $line => $comment) {
            $this->log->info("Adding comment to line=$line, file=$filename", [
                'line' => $comment,
                'file' => $comment,
                'text' => $comment,
            ]);
            $this->bitBucket->addPullRequestComment(
                $slug,
                $repo,
                $pullRequestId,
                $filename,
                $line,
                $comment
            );
        }
    }

    /**
     * Strikes text
     * @see https://confluence.atlassian.com/bitbucketserver0411/markdown-syntax-guide-861180510.html?utm_campaign=in-app-help&utm_medium=in-app-help&utm_source=stash
     * @param $text
     * @return mixed
     */
    private function getStrikeThroughedCommentText($text)
    {
        return preg_replace("/^([^~\n\r].*[^~\n\r])$/", "~~$1~~", $text);
    }

    /**
     * Removes robot comments for code, which is outdated now. This comments does not returns with file comments
     * @param string $slug
     * @param string $repo
     * @param int $pullRequestId
     */
    protected function removeOutdatedRobotComments($slug, $repo, $pullRequestId)
    {
        $activities = $this->bitBucket->getPullRequestActivities(
            $slug,
            $repo,
            $pullRequestId
        )['values'];

        foreach ($activities as $activity) {
            if ($activity['action'] != 'COMMENTED' || $activity['commentAction'] != 'ADDED') {
                continue;
            }

            if (empty($activity['commentAnchor'])) {
                continue;
            }

            if (!$activity['commentAnchor']['orphaned']) {
                $this->log->debug("Skip activity #{$activity['id']} as not orphaned");
                continue;
            }

            if ($activity['user']['name'] != $this->bitBucket->getUserName()) {
                continue;
            }

            if (empty($activity['comment']['id'])) {
                $this->log->info("Cannot delete activity #{$activity['id']} as comment id not found");
                continue;
            }

            if (empty($activity['comment']['comments'])) {
                $this->bitBucket->deletePullRequestComment(
                    $slug,
                    $repo,
                    $pullRequestId,
                    $activity['comment']['version'],
                    $activity['comment']['id']
                );
                $this->log->debug("Delete activity #{$activity['id']} (commentId {$activity['comment']['id']}) as orphaned");
            } else {
                //If there are replies to our comment - just strike through our message
                //@see https://confluence.atlassian.com/display/STASH0310/Markdown+syntax+guide#Markdownsyntaxguide-Characterstyles
                $this->bitBucket->updatePullRequestComment(
                    $slug,
                    $repo,
                    $pullRequestId,
                    $activity['comment']['id'],
                    $activity['comment']['version'],
                    preg_replace("/^([^~]+)/s", "~~$1", $activity['comment']["text"])
                );
            }

        }
    }

    /**
     * Converts array of numbers to human-readable string. Example, [1,2,3,4,10,11] -> "1-4,10,11"
     * @param array $numbers - input numbers array
     * @return string
     */
    private function visualizeNumbersToInterval($numbers)
    {
        $result = [];
        sort($numbers);
        $prev = -1;
        $first = null;
        foreach ($numbers as $val) {
            if ($prev === -1) {
                $first = $val;
                $prev = $val;
                continue;
            }

            if ($prev + 1 != $val) {
                if ($first == $prev) {
                    $result[] = $first;
                } elseif ($first + 1 == $prev) {
                    $result[] = $first;
                    $result[] = $prev;
                } else {
                    $result[] = "$first-$prev";
                }
                $first = $val;
            }
            $prev = $val;
        }

        if ($first == $prev) {
            $result[] = $first;
        } else {
            $result[] = "$first-$prev";
        }

        return implode(",", $result);
    }
}
