<?php

/*
 * This file is part of the FOSHttpCacheBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\HttpCacheBundle\EventListener;

use FOS\HttpCacheBundle\CacheManager;
use FOS\HttpCacheBundle\Http\SymfonyResponseTagger;
use FOS\HttpCacheBundle\Configuration\Tag;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Event handler for the cache tagging tags.
 *
 * @author David de Boer <david@driebit.nl>
 */
class TagListener extends AbstractRuleListener implements EventSubscriberInterface
{
    /**
     * @var CacheManager
     */
    private $cacheManager;

    /**
     * @var SymfonyResponseTagger
     */
    private $symfonyResponseTagger;

    /**
     * @var ExpressionLanguage
     */
    private $expressionLanguage;

    /**
     * Constructor.
     *
     * @param CacheManager            $cacheManager
     * @param SymfonyResponseTagger   $tagHandler
     * @param ExpressionLanguage|null $expressionLanguage
     */
    public function __construct(
        CacheManager $cacheManager,
        SymfonyResponseTagger $tagHandler,
        ExpressionLanguage $expressionLanguage = null
    ) {
        $this->cacheManager = $cacheManager;
        $this->symfonyResponseTagger = $tagHandler;
        $this->expressionLanguage = $expressionLanguage ?: new ExpressionLanguage();
    }

    /**
     * Process the _tags request attribute, which is set when using the Tag
     * annotation.
     *
     * - For a safe (GET or HEAD) request, the tags are set on the response.
     * - For a non-safe request, the tags will be invalidated.
     *
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $tags = [];
        // Only set cache tags or invalidate them if response is successful
        if ($response->isSuccessful()) {
            $tags = $this->getAnnotationTags($request);
        }

        $configuredTags = $this->matchRule($request, $response);
        if ($configuredTags) {
            $tags = array_merge($tags, $configuredTags['tags']);
            foreach ($configuredTags['expressions'] as $expression) {
                $tags[] = $this->evaluateTag($expression, $request);
            }
        }

        if ($request->isMethodCacheable()) {
            $this->symfonyResponseTagger->addTags($tags);
            if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()) {
                // For safe requests (GET and HEAD), set cache tags on response
                $this->symfonyResponseTagger->tagSymfonyResponse($response);
            }
        } elseif (count($tags)) {
            // For non-safe methods, invalidate the tags
            $this->cacheManager->invalidateTags($tags);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    /**
     * Get the tags from the annotations on the controller that was used in the
     * request.
     *
     * @param Request $request
     *
     * @return array List of tags affected by the request
     */
    private function getAnnotationTags(Request $request)
    {
        // Check for _tag request attribute that is set when using @Tag
        // annotation
        /** @var $tagConfigurations Tag[] */
        if (!$tagConfigurations = $request->attributes->get('_tag')) {
            return [];
        }

        $tags = [];
        foreach ($tagConfigurations as $tagConfiguration) {
            if (null !== $tagConfiguration->getExpression()) {
                $tags[] = $this->evaluateTag(
                    $tagConfiguration->getExpression(),
                    $request
                );
            } else {
                $tags = array_merge($tags, $tagConfiguration->getTags());
            }
        }

        return $tags;
    }

    /**
     * Evaluate a tag that contains expressions.
     *
     * @param string  $expression
     * @param Request $request
     *
     * @return string Evaluated tag
     */
    private function evaluateTag($expression, Request $request)
    {
        $values = $request->attributes->all();
        // if there is an attribute called "request", it needs to be accessed through the request.
        $values['request'] = $request;

        return $this->expressionLanguage->evaluate($expression, $values);
    }
}
