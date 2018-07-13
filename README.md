# Implementing a Moderation Workflow using Zenaton

Zenaton is making it very easy for everyone to create asynchronous workflow.
For example, if you are working on a blog application, you can make it very easy to have a moderation workflow.

Imagine you want to implement the following use case:
- When an author writes a new blog post, you want to prevent it from being published until it has been approved by a moderator.
- Moderators have 2 days to approve or reject the publication of a blog post
- If no moderator has approved a post within 2 days, the post will be automatically approved.

Implementing this workflow in your existing is very easy using Zenaton.

First, add Zenaton PHP SDK into your application using composer:
composer require zenaton/zenaton-php

Then, you must initialize a Zenaton\Client instance. This can be done in an event listener listenning to the kernel.request event:

```
<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenaton\Client;

class InitializeZenatonSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'initializeZenaton',
        ];
    }

    public function initializeZenaton(KernelEvent $event): void
    {
        if ($event->isMasterRequest()) {
            Client::init(getenv('ZENATON_APP_ID'), getenv('ZENATON_API_TOKEN'), getenv('ZENATON_APP_ENV'));
        }
    }
}
```

Next, you can define your workflow by defining a class implementing the `Zenaton\Interfaces\WorkflowInterface`. There is also the `Zenaton\Traits\Zenatonable` trait that will help you implement your workflow:

```
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
```

Our workflow is pretty simple: It will wait for a `PostModeratedEvent` for a maximum of 2 days. If the event is not triggered within two days, the `$event` variable will be `null` so in that case we will run a `AutoPublishPostTask` to automatically approve the publication of the blog post.

The `AutoPublishPostTask` does a really simple thing: It finds the post using the identifier passed as an argument to the constructor and set the state of the post to the published state:

```
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
```

The last class to implement is the `PostModeratedEvent` class, which will hold the new publication state of the blog post when a moderator decides to approve or reject it:

```
<?php

namespace App\Async\Event;

use Zenaton\Interfaces\EventInterface;

class PostModeratedEvent implements EventInterface
{
    public $state;

    public function __construct($state)
    {
        $this->state = $state;
    }
}
```

We now have every classes we need in order to run the workflow. The last things to do are:
- Starting the ModerationWorkflow when a new blog post is written.
- Sending the event to the workflow when a moderator approves or rejects the blog post.

Starting the workflow is as simple as instanciating a new instance of your workflow and calling the `dispatch` method:
You can add the following code in the action creating your new blog post:

```
(new ModerationWorkflow($post))->dispatch();
```

Notifying the workflow of an event is done by retrieving the workflow by its identifier and calling the `send` method on it to send an instance of the event class.
Again, the Zenaton PHP SDK makes it very easy to do so. For example, you can add in your moderation action the following line of code:

```
ModerationWorkflow::whereId($post->getId())->send(new PostModeratedEvent($state));
```

You're done ! The use case we defined is fully implemented using Zenaton, by defining a few classes, with just a few lines of code.



# Dependency injection in tasks

Having dependency injection in tasks is quite difficult because Symfony
handles dependency injection in services, and injects dependencies when the container
instanciates services. Even if we decided to make our tasks services, the injections
will take place in the constructor or in setters, and dependencies are kept into properties.

This will be an issue because task classes are serialized when using zenaton and we don't really want
to serialize the task and all its dependencies, we would like to have a very lightweight serialization,
and be able to inject dependencies when we execute the tasks.

I made a very naive implementation of this, working only for public services.

First, I removed the `handle()` method from the `TaskInterface`.

It allowed me to be able to put any number of parameters needed on the handle method.

Then, in the `Worker` class, just before calling the `handle` method on the task, I'm using some
reflection to get what parameters are needed, and use the symfony kernel we booted earlier to
get services that will be needed when calling the handle method.

This very naive implementation is the following:

```
public function getArguments($task)
{
    $arguments = [];

    $reflClass = new \ReflectionClass($task);
    $reflHandleMethod = $reflClass->getMethod('handle');
    $reflParameters = $reflHandleMethod->getParameters();
    foreach ($reflParameters as $parameter) {
        $reflClass = $parameter->getClass();
        $classname = $reflClass->getName();
        $arguments[] = $this->kernel->getContainer()->get($classname);
    }

    return $arguments;
}
```

Finally, calling the `handle` method is done using call_user_func_array()
to use the return from `getArguments()` as the second parameter.

It makes dependency injection on the method call to work, but only for public services.

A better implementation would be to try to replicate how it's done for Symfony controllers:

First, we will need a ZenatonBundle to make everything transparent for our users.

We will need to register for autoconfiguration implementations of `TaskInterface`.
We could also completely remove the `TaskInterface` and consider tasks to be of type \callable.
This will allow users to define tasks also using anonymous functions, and we should be
able to have dependency injection on these tasks also.

We will then, at container compile time, create public service locators for tasks, like symfony does
it for the controllers (See `RegisterControllerArgumentLocatorsPass`).

We will then be able to retrieve them from the container to be able to proceed to method dependency injection
at runtime (See `HttpKernel` and `ArgumentResolver`).

Finally, we will need to introduce different Worker classes depending on the framework
we use because dependency injection in task in Laravel will like not be the same.
