<?php

namespace App\Async\Task;

use App\Entity\Post;
use Zenaton\Interfaces\TaskInterface;
use Zenaton\Traits\Zenatonable;

class AutoPublishPostTask implements TaskInterface
{
    use Zenatonable;

    protected $postId;

    public function __construct($postId)
    {
        $this->postId = $postId;
    }

    public function handle()
    {
        $em = container()->get('doctrine')->getManager();
        $repository = $em->getRepository(Post::class);
        $post = $repository->find($this->postId);
        $post->setState(Post::STATE_PUBLISHED);
        $em->flush();
    }
}
