<?php

namespace Playbloom\Satisfy\Webhook;

use Playbloom\Satisfy\Event\BuildEvent;
use Playbloom\Satisfy\Model\BuildContext;
use Playbloom\Satisfy\Model\RepositoryInterface;
use Playbloom\Satisfy\Service\Manager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

abstract class AbstractWebhook
{
    protected EventDispatcherInterface $dispatcher;

    protected Manager $manager;

    protected ?string $secret = null;
    protected bool $debug = false;

    public function __construct(Manager $manager, EventDispatcherInterface $dispatcher)
    {
        $this->manager = $manager;
        $this->dispatcher = $dispatcher;
    }

    public function setSecret(?string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function getResponse(Request $request): Response
    {
        try {
            $this->validate($request);
            $repository = $this->getRepository($request);
            $status = $this->handle($repository, $context);
        } catch (\InvalidArgumentException $exception) {
            return new Response(json_encode($this->getArrayFromException($exception)), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return new Response(json_encode($this->getArrayFromException($exception)), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $success = 0 === $status;
        if ($this->debug && null !== $context) {
            $content = [
                'status' => $status,
                'exit_code' => $context->getExitCode(),
                'command' => $context->getCommand(),
                'output' => $context->getOutput(),
                'error_output' => $context->getErrorOutput(),
                'exception' => null,
            ];

            $throwable = $context->getThrowable();
            if (null !== $throwable) {
                $content['exception'] = $this->getArrayFromException($throwable);
            }

            $status = json_encode($content);
        }

        return new Response((string) $status, $success ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function handle(RepositoryInterface $repository, ?BuildContext &$context): ?int
    {
        $event = new BuildEvent($repository);
        $this->dispatcher->dispatch($event);
        $context = $event->getContext();

        return $event->getStatus();
    }

    /**
     * @throws \InvalidArgumentException
     */
    abstract protected function validate(Request $request): void;

    /**
     * @throws \InvalidArgumentException
     */
    abstract protected function getRepository(Request $request): RepositoryInterface;

    protected function findRepository(array $urls): ?RepositoryInterface
    {
        foreach ($urls as $url) {
            $repository = $this->manager->findByUrl($url);
            if ($repository) {
                return $repository;
            }
        }

        return null;
    }

    /**
     * @param \Throwable $throwable
     * @return array
     */
    private function getArrayFromException(\Throwable $throwable): array
    {
        return [
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
        ];
    }
}
