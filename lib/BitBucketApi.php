<?php
/**
 * @author Artem Naumenko
 *
 * Класс для интеграции с atlassian bitbucket. Реализует базовую функциональность API
 * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html
 */
namespace PhpCsBitBucket;

use PhpCsBitBucket\BitBacket\Collection\Blame as BlameCollection;
use PhpCsBitBucket\BitBacket\Item\Blame;
use PhpCsBitBucket\BitBacket\Item\Commit;
use PhpCsBitBucket\BitBacket\Item\Person;
use PhpCsBitBucket\Exception\BitBucketFileInConflict;
use GuzzleHttp\Client;
use Monolog\Logger;
use \GuzzleHttp\Exception\RequestException;

/**
 * Class BitBucketApi
 * @package PhpCsBitBucket
 */
class BitBucketApi
{
    const HTTP_TIMEOUT = 90;

    /** @var Client */
    private $httpClient;

    /** @var Logger */
    private $logger;

    private $username;

    /**
     * @var array
     */
    protected $blameCache = [];

    /**
     * @var array
     */
    protected $commitCache = [];

    /**
     * BitBucketApi constructor.
     * @param Logger $logger   объект для журналирования
     * @param string $url      ссылка на bitbucket со слешом на конце. Например: http://atlassian-stash.com/
     * @param string $user     пользователь, от имени которого будут делаться запросы
     * @param string $password пароль пользователя
     */
    public function __construct(Logger $logger, $url, $user, $password, $timeout = self::HTTP_TIMEOUT)
    {
        $this->username = $user;
        $this->logger = $logger;

        $config = [
            'base_uri' => "{$url}rest/api/1.0/",
            'timeout' => $timeout,
            'headers' => [
                'Content-type' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate, br',
            ],
            'allow_redirects' => true,
            'auth' => [$user, $password],
        ];
        $this->httpClient = new Client($config);
    }

    /**
     * Возвращает имя текущего пользователя
     * @return string
     */
    public function getUserName()
    {
        return $this->username;
    }

    /**
     * Возвращает содержимое файла в данной ветке
     *
     * @param string $slug
     * @param string $repo
     * @param string $ref
     * @param string $filename
     * @return string
     */
    public function getFileContent($slug, $repo, $pullRequestId, $filename)
    {
        $changes = $this->getPullRequestDiffs($slug, $repo, $pullRequestId, 100000, $filename);

        $result = [];
        foreach ($changes['diffs'] as $diff) {
            if ($diff['destination']['toString'] !== $filename) {
                continue;
            }

            if (!empty($diff['hunks'])) {
                foreach ($diff['hunks'] as $hunk) {
                    foreach ($hunk['segments'] as $segment) {
                        foreach ($segment['lines'] as $line) {
                            if (!empty($line['conflictMarker'])) {
                                throw new BitBucketFileInConflict("File $filename is in conflict state");
                            }

                            $result[$line['destination']] = $line['line'];
                        }
                    }
                }
            }
        }

        ksort($result);

        return implode("\n", $result)."\n";
    }

    /**
     * Возвращает содержимое файла в данной ветке
     *
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param int    $contextLines
     * @param string $path
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp992528
     */
    public function getPullRequestDiffs($slug, $repo, $pullRequestId, $contextLines = 10, $path = "")
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/diff/$path", "GET", [
            'contextLines' => $contextLines,
            'withComments' => 'false',
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param string $filename
     *
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp36368
     */
    public function getPullRequestComments($slug, $repo, $pullRequestId, $filename)
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments", "GET", [
            'path' => $filename,
            'limit' => 1000,
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param string $filename
     *
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp137840
     */
    public function getPullRequestActivities($slug, $repo, $pullRequestId)
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/activities", "GET", [
            'limit' => 1000,
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     */
    public function addMeToPullRequestReviewers($slug, $repo, $pullRequestId)
    {
        $user = [
            "name" => $this->username,
        ];

        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/participants", "POST", [
            'user' => $user,
            'role' => "REVIEWER",
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     */
    public function approvePullRequest($slug, $repo, $pullRequestId)
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/approve", "POST",
            []
        );
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     */
    public function unapprovePullRequest($slug, $repo, $pullRequestId)
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/approve", "DELETE",
            []
        );
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param string $filename
     * @param int    $line
     * @param string $text
     * @return array
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp895840
     */
    public function addPullRequestComment($slug, $repo, $pullRequestId, $filename, $line, $text)
    {
        if ($line) {
            $anchor = [
                "line" => $line,
                "lineType" => "ADDED",
                "fileType" => "TO",
                'path' => $filename,
                'srcPath' => $filename,
            ];
        } else {
            $anchor = [
                'path' => $filename,
                'srcPath' => $filename,
            ];
        }


        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments", "POST", [
            'text' => $text,
            'anchor' => $anchor,
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param int    $commentId
     * @param int    $version
     * @return array
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp895840
     */
    public function deletePullRequestComment($slug, $repo, $pullRequestId, $version, $commentId)
    {
        return $this->sendRequest(
            "projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments/$commentId/?version=$version",
            "DELETE",
            []
        );
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param int    $commentId
     * @param int    $version
     * @param string $text
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp1467264
     */
    public function updatePullRequestComment($slug, $repo, $pullRequestId, $commentId, $version, $text)
    {
        $request = [
            'version' => $version,
            'text' => $text,
        ];

        return $this->sendRequest(
            "projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments/$commentId",
            "PUT",
            $request
        );
    }

    /**
     * Максмимальное количество пул реквестов - 100. Расчитываю на то что не будет более 100 пулреквестов на одну
     * фичевую ветку :)
     *
     * @param string $slug
     * @param string $repo
     * @param string $ref
     * @return array
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp992528
     */
    public function getPullRequestsByBranch($slug, $repo, $ref)
    {
        $query = [
            "state" => "open",
            "at" => $ref,
            "direction" => "OUTGOING",
            "limit" => 100,
        ];

        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests", "GET", $query);
    }

    /**
     * @param string $project
     * @param string $repo
     * @param string $path
     * @param string $at // ag: the commit ID or ref to retrieve the content for
     *
     * @return BlameCollection
     *
     * @throws BitBacket\Exception\BlameDuplicateLineException
     */
    public function getFileBlame($project, $repo, $path, $at)
    {
        if (!isset($this->blameCache[$at][$path])) {
            $this->logger->debug("Getting blame for {$path}");

            $blameResponse = $this->sendRequest(
                "projects/$project/repos/$repo/browse/$path",
                'GET',
                [
                    'at' => $at,
                    'blame' => true,
                    'noContent' => true,
                ]
            );

            $blameCollection = new BlameCollection($path);
            foreach ($blameResponse as $blameLine) {
                $blameCollection->addItem(
                    new Blame(
                        $blameLine['lineNumber'],
                        $blameLine['spannedLines'],
                        $blameLine['fileName'],
                        $blameLine['commitId'],
                        $blameLine['commitDisplayId'],
                        new Person(
                            $blameLine['author']['id'] ?? null,
                            $blameLine['author']['name'],
                            $blameLine['author']['emailAddress'],
                            $blameLine['author']['displayName'] ?? null
                        ),
                        $blameLine['authorTimestamp'],
                        new Person(
                            $blameLine['committer']['id'] ?? null,
                            $blameLine['committer']['name'],
                            $blameLine['committer']['emailAddress'],
                            $blameLine['committer']['displayName'] ?? null
                        ),
                        $blameLine['committerTimestamp']
                    )
                );
            }

            $this->blameCache[$at][$path] = $blameCollection;
        }

        return $this->blameCache[$at][$path];
    }

    /**
     * @param string $project
     * @param string $repo
     * @param string $commitId
     *
     * @return Commit
     */
    public function getCommitById($project, $repo, $commitId)
    {
        if (!isset($this->commitCache[$commitId])) {
            $commitArray = $this->sendRequest("projects/{$project}/repos/{$repo}/commits/{$commitId}", 'GET', []);

            $this->commitCache[$commitId] = new Commit(
                $commitArray['id'],
                $commitArray['displayId'],
                new Person(
                    $commitArray['author']['id'] ?? null,
                    $commitArray['author']['name'],
                    $commitArray['author']['emailAddress'],
                    $commitArray['author']['displayName'] ?? null
                ),
                $commitArray['authorTimestamp'],
                new Person(
                    $commitArray['committer']['id'] ?? null,
                    $commitArray['committer']['name'],
                    $commitArray['committer']['emailAddress'],
                    $commitArray['committer']['displayName'] ?? null
                ),
                $commitArray['committerTimestamp'],
                $commitArray['message']
            );
        }

        return $this->commitCache[$commitId];
    }

    /**
     * @param string $url
     * @param string $method
     * @param array $request
     *
     * @return array | bool
     *
     * @throws \Exception
     */
    private function sendRequest($url, $method, array $request)
    {
        try {
            if (strtoupper($method) == 'GET') {
                $this->logger->debug("Sending GET request to $url, query=" . json_encode($request));
                $reply = $this->httpClient->request('GET', $url, ['query' => $request]);
            } else {
                $this->logger->debug("Sending $method request to $url, body=" . json_encode($request));
                $reply = $this->httpClient->request($method, $url, ['body' => json_encode($request)]);
            }
        } catch (RequestException $e) {
            //bitbucket error: it can't send more then 1mb of json data. So just skip suck pull requests or files
            $this->logger->debug("Request finished with error: " . $e->getMessage());
            if ($e->getMessage() == 'cURL error 56: Problem (3) in the Chunked-Encoded data') {
                throw new Exception\BitBucketJsonFailure($e->getMessage(), $e->getRequest(), $e->getResponse(), $e);
            } else {
                throw $e;
            }
        }

        $this->logger->debug("Request finished");

        $json = (string) $reply->getBody();

        //an: пустой ответ - значит все хорошо
        if (empty($json)) {
            return true;
        }

        $data = json_decode($json, true);

        if ($data === null && $data != 'null') {
            $this->logger->addError("Invalid json received", [
                'url' => $url,
                'method' => $method,
                'request' => $request,
                'reply' => $json,
            ]);

            throw new \Exception('invalid_json_received');
        }

        return $data;
    }
}
