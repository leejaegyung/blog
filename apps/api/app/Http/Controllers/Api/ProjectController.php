<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\KeywordProject;
use App\Support\Platform;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        // ?kind=category 이면 학습 카테고리(관리 › 카테고리별 학습), 아니면 키워드
        $kind = $request->query('kind') === KeywordProject::KIND_CATEGORY ? KeywordProject::KIND_CATEGORY : KeywordProject::KIND_KEYWORD;
        $projects = $request->user()->keywordProjects()
            ->where('kind', $kind)
            ->when(in_array($request->query('platform'), Platform::ALL, true), fn ($q) => $q->where('platform', $request->query('platform')))
            ->withCount(['references', 'posts', 'categoryPosts'])
            ->with('learningCategory:id,keyword')
            ->latest()
            ->get();

        return ProjectResource::collection($projects);
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $project = $request->user()->keywordProjects()->create($request->validated());

        return new ProjectResource($project->refresh()->loadCount(['references', 'posts', 'categoryPosts']));
    }

    public function show(KeywordProject $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->loadCount(['references', 'posts', 'categoryPosts'])->load('learningCategory:id,keyword'));
    }

    public function update(ProjectRequest $request, KeywordProject $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($project->loadCount(['references', 'posts', 'categoryPosts'])->load('learningCategory:id,keyword'));
    }

    public function destroy(KeywordProject $project): Response
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return response()->noContent();
    }
}
