<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\KeywordProject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = $request->user()->keywordProjects()
            ->withCount(['references', 'posts'])
            ->latest()
            ->get();

        return ProjectResource::collection($projects);
    }

    public function store(ProjectRequest $request): ProjectResource
    {
        $project = $request->user()->keywordProjects()->create($request->validated());

        return new ProjectResource($project->refresh()->loadCount(['references', 'posts']));
    }

    public function show(KeywordProject $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->loadCount(['references', 'posts']));
    }

    public function update(ProjectRequest $request, KeywordProject $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($project->loadCount(['references', 'posts']));
    }

    public function destroy(KeywordProject $project): Response
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return response()->noContent();
    }
}
