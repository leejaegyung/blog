<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Support\PostContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['project_id' => ['sometimes', 'integer']]);

        $posts = $request->user()->posts()
            ->when($request->filled('project_id'), fn ($q) => $q->where('keyword_project_id', $request->integer('project_id')))
            ->withCount(['images', 'facts'])
            ->with('project:id,keyword')
            ->latest()
            ->get();

        return PostResource::collection($posts);
    }

    public function store(PostRequest $request): PostResource
    {
        $post = DB::transaction(function () use ($request) {
            $post = $request->user()->posts()->create($request->safe()->except('facts'));
            $this->syncFacts($post, $request->validated('facts', []));

            return $post;
        });

        return $this->resource($post);
    }

    public function show(Post $post): PostResource
    {
        Gate::authorize('view', $post);

        return $this->resource($post);
    }

    public function update(PostRequest $request, Post $post): PostResource
    {
        Gate::authorize('update', $post);

        DB::transaction(function () use ($request, $post) {
            $post->update($request->safe()->except(['facts', 'content']));
            if ($request->has('facts')) {
                $this->syncFacts($post, $request->validated('facts'));
            }
            if ($request->has('content')) {
                $this->saveContent($post, $request->validated('content'));
            }
        });

        return $this->resource($post);
    }

    /**
     * 사실 목록은 부분 수정 없이 통째로 교체한다(입력 화면이 목록 전체를 보낸다).
     *
     * @param  list<array{fact_key: string, fact_value: string}>  $facts
     */
    private function syncFacts(Post $post, array $facts): void
    {
        $post->facts()->delete();

        foreach (array_values($facts) as $order => $fact) {
            $post->facts()->create([
                'fact_key' => $fact['fact_key'],
                'fact_value' => $fact['fact_value'],
                'source_type' => 'user',
                'verified' => true,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * 편집기에서 저장한 본문. 텍스트와 글자 수·키워드 횟수를 다시 계산한다(초안 경고는 그대로 둔다).
     *
     * @param  array{blocks: list<array<string, mixed>>, tags: list<string>}  $content
     */
    private function saveContent(Post $post, array $content): void
    {
        $blocks = array_map(fn (array $block) => array_filter([
            'type' => $block['type'],
            'text' => $block['text'] ?? null,
            'image_id' => $block['image_id'] ?? null,
            'items' => $block['items'] ?? null,
        ], fn ($value) => $value !== null), $content['blocks']);

        $post->forceFill([
            'content_json' => ['blocks' => $blocks, 'tags' => array_values($content['tags'])],
            'content_text' => PostContent::text($post->title, $blocks),
            'draft_meta_json' => array_merge($post->draft_meta_json ?? [], [
                'char_count' => PostContent::charCount($blocks),
                'keyword_count' => PostContent::keywordCount($blocks, $post->project?->keyword ?? ''),
            ]),
        ])->save();
    }

    private function resource(Post $post): PostResource
    {
        return new PostResource($post->refresh()->load(['project', 'facts', 'images']));
    }
}
