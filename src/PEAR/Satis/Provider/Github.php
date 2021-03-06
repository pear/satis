<?php
namespace PEAR\Satis\Provider;

use PEAR\Satis;
use PEAR\Satis\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Github
{
    private $dispatcher;

    private $orgs;

    private $request;

    private $token;

    /**
     * @param array  $orgs
     * @param string $token
     * @param EventDispatcher $dispatcher
     *
     * @return self
     */
    public function __construct(array $orgs, $token, EventDispatcher $dispatcher)
    {
        $this->orgs = $orgs;
        $this->token = $token;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Crawl Github for repositories.
     *
     * @param array $filter
     *
     * @return array
     *
     * @throws \RuntimeException
     */
    public function provide(array $filter = [])
    {
        $repositories = [];

        foreach ($this->orgs as $org) {
            $repositories = array_merge($repositories, $this->crawl($org, $filter));
            $this->dispatcher->dispatch(Event::CRAWLED_ORG);
        }

        return $repositories;
    }

    private function crawl($org, array $filter)
    {
        $url = sprintf(
            "https://api.github.com/orgs/%s/repos?type=public&access_token=%s",
            $org,
            $this->token
        );

        $request = $this->getRequest();

        $repositories = [];

        while (true) {

            $response = $request->setUrl($url)->send();

            $body = $this->parseResponse($response);

            foreach ($body as $repository) {

                if (in_array($repository['name'], $filter)) {
                    $this->dispatcher->dispatch(Satis\Event::REPO_IGNORED, new Event\BuildSatisJson($repository));
                    continue;
                }

                if (false !== $repository['fork']) {
                    $this->dispatcher->dispatch(Satis\Event::REPO_IS_FORK, new Event\BuildSatisJson($repository));
                    $repository = $this->findUpstream((int) $repository['id']);
                }
                $repositories[] = $repository['clone_url'];
            }

            $url = $this->extractNext($response->getHeader('Link'));
            if (false === $url) {
                break;
            }
        }

        return $repositories;
    }

    private function extractNext($linkHeader)
    {
        if (false === strpos($linkHeader, 'rel="next"')) {
            return false;
        }

        $linkHeaders = explode(',', $linkHeader);
        foreach ($linkHeaders as $header) {
            if (false === strpos($header, 'rel="next"')) {
                continue;
            }

            list($link, $relation) = explode(';', $header);
            return substr($link, 1, -1);
        }

        return false;
    }

    /**
     * Find the upstream repository. We use 'source' to find the ultimate one.
     *
     * @param int $repositoryId
     *
     * @return array
     */
    private function findUpstream($repositoryId)
    {
        $url = sprintf("https://api.github.com/repositories/%d?access_token=%s", $repositoryId, $this->token);
        $response = $this->getRequest()->setUrl($url)->send();

        $body = $this->parseResponse($response);
        return $body['source']; // ultimate source
    }

    private function getRequest()
    {
        if ($this->request instanceof \HTTP_Request2) {
            return $this->request;
        }

        $this->request = new \HTTP_Request2;
        $this->request->setMethod(\HTTP_Request2::METHOD_GET);
        return $this->request;
    }

    /**
     * @param \HTTP_Request2_Response $response
     *
     * @return array
     * @throws \RuntimeException
     */
    private function parseResponse(\HTTP_Request2_Response $response)
    {
        $body = json_decode($response->getBody(), true);

        if (JSON_ERROR_NONE !== json_last_error()) {

            $msg = sprintf(
                "Failed to decode response. Error: %s (%d), Response was: %s",
                json_last_error_msg(),
                json_last_error(),
                $response->getBody()
            );

            throw new \RuntimeException($msg);
        }

        if (200 !== $response->getStatus()) {
            throw new \RuntimeException($body['message']);
        }

        return $body;
    }
}
