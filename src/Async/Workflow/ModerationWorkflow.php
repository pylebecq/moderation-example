<?php

namespace App\Async\Workflow;

use App\Async\Event\PostModeratedEvent;
use App\Async\Task\AutoPublishPostTask;
use Zenaton\Interfaces\WorkflowInterface;
use Zenaton\Tasks\Wait;
use Zenaton\Traits\Zenatonable;

class ModerationWorkflow implements WorkflowInterface
{
    use Zenatonable;

    /** @var \App\Entity\Post */
    protected $post;

    public function __construct($post)
    {
        $this->post = $post;
    }

    public function handle()
    {
        /** @var PostModeratedEvent $event */
        $event = (new Wait(PostModeratedEvent::class))->days(2)->execute();
        if (!$event) {
            (new AutoPublishPostTask($this->post->getId()))->execute();
        }
    }

    public function getId()
    {
        return $this->post->getId();
    }
}
