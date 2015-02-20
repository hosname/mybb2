<?php
/**
 * Thread repository implementation, using Eloquent ORM.
 *
 * @version 2.0.0
 * @author MyBB Group
 * @license LGPL v3
 */

namespace MyBB\Core\Database\Repositories\Eloquent;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use MyBB\Core\Database\Models\Forum;
use MyBB\Core\Database\Models\Topic;
use MyBB\Core\Database\Repositories\IPostRepository;
use MyBB\Core\Database\Repositories\ITopicRepository;

class TopicRepository implements ITopicRepository
{
    /**
     * @var Topic $topicModel
     * @access protected
     */
    protected $topicModel;
    /**
     * @var Guard $guard;
     * @access protected
     */
    protected $guard;
    /**
     * @var IPostRepository $postRepository
     * @access protected
     */
    protected $postRepository;

    private $dbManager;
    /**
     * @var Str $stringUtils
     * @access protected
     */
    protected $stringUtils;

    /**
     * @param Topic           $topicModel     The model to use for threads.
     * @param Guard           $guard          Laravel guard instance, used to get user ID.
     * @param IPostRepository $postRepository Used to manage posts for topics.
     * @param Str             $stringUtils    String utilities, used for creating slugs.
     * @param DatabaseManager $dbManager Database manager, needed to do transactions.
     */
    public function __construct(Topic $topicModel, Guard $guard, IPostRepository $postRepository, Str $stringUtils, DatabaseManager $dbManager) // TODO: Inject permissions container? So we can check thread permissions before querying?
    {
        $this->topicModel = $topicModel;
        $this->guard = $guard;
        $this->postRepository = $postRepository;
        $this->stringUtils = $stringUtils;
        $this->dbManager = $dbManager;
    }

    /**
     * Get all threads.
     *
     * @return mixed
     */
    public function all()
    {
        return $this->topicModel->all();
    }

    /**
     * Get all threads created by a user.
     *
     * @param int $userId The ID of the user.
     *
     * @return mixed
     */
    public function allForUser($userId = 0)
    {
        return $this->topicModel->where('user_id', '=', $userId)->get();
    }

    /**
     * Find a single thread by ID.
     *
     * @param int $id The ID of the thread to find.
     *
     * @return mixed
     */
    public function find($id = 0)
    {
        return $this->topicModel->find($id);
    }

    /**
     * Find a single thread by its slug.
     *
     * @param string $slug The slug of the thread. Eg: 'my-first-thread'.
     *
     * @return mixed
     */
    public function findBySlug($slug = '')
    {
        return $this->topicModel->with(['author', 'firstPost'])->where('slug', '=', $slug)->first();
    }

    /**
     * Get all threads within a forum.
     *
     * @param Forum $forum The forum the threads belong to.
     *
     * @return mixed
     */
    public function allForForum(Forum $forum)
    {
        return $this->topicModel->with(['posts', 'author'])->where('forum_id', '=', $forum->id)->get();
    }

    /**
     * Create a new topic
     *
     * @param array $details Details about the topic.
     *
     * @return mixed
     */
    public function create(array $details = [])
    {
        $details = array_merge([
            'title' => '',
            'forum_id' => 0,
            'user_id' => $this->guard->user()->id,
            'first_post_id' => 0,
            'last_post_id' => 0,
            'views' => 0,
            'num_posts' => 0,
            'content' => '',
                               ], $details);

        $details['slug'] = $this->createSlugForTitle($details['title']);

        $topic = null;

        $this->dbManager->transaction(function() use ($details, &$topic) {
            $topic = $this->topicModel->create([
                'title' => $details['title'],
                'slug' => $details['slug'],
                'forum_id' => $details['forum_id'],
                'user_id' => $details['user_id'],
                                               ]);

            $firstPost = $this->postRepository->addPostToTopic($topic, [
                'content' => $details['content'],
            ]);

            $topic->update([
                'first_post_id' => $firstPost->id,
                'last_post_id' => $firstPost->id,
                'num_posts' => 1,
                           ]);
        });

        return $topic;
    }

    /**
     * Create a unique slug for a topic title.
     *
     * @param string $title The title of the topic.
     *
     * @return string The slugged title.
     */
    private function createSlugForTitle($title = '')
    {
        $title = (string) $title;
        $sluggedTitle = $this->stringUtils->slug($title, '-');

        $numExistingWithSlug = $this->topicModel->where('slug' ,'LIKE', $sluggedTitle . '%')->count();

        if ($numExistingWithSlug > 0) {
            $sluggedTitle .= '-' . $numExistingWithSlug;
        }

        return $sluggedTitle;
    }
}