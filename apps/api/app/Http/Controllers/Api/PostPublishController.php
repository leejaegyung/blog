<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Publishing\ManualExportPublisher;
use App\Publishing\PostExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class PostPublishController extends Controller
{
    /** record=false면 화면이 미리 준비만 하는 것이라 올리기 기록(publish_jobs)을 남기지 않는다 */
    public function export(Request $request, Post $post, ManualExportPublisher $publisher): JsonResponse
    {
        Gate::authorize('update', $post);

        $validation = $publisher->validate($post);
        if (! $validation->passes()) {
            throw ValidationException::withMessages(['post' => $validation->errors]);
        }

        $result = $publisher->publish($post);
        if ($request->boolean('record', true)) {
            $post->publishJobs()->create([
            'publisher' => $publisher->key(),
            'status' => $result->status,
            'attempt' => $post->publishJobs()->where('publisher', $publisher->key())->count() + 1,
            'finished_at' => now(),
            ]);
        }

        return response()->json(['data' => $result->payload + ['warnings' => $validation->warnings]]);
    }

    /** 본문에 넣은 사진을 [사진 N] 번호와 같은 파일 이름(01.jpg …)으로 묶어 내려준다. */
    public function photos(Post $post): BinaryFileResponse
    {
        Gate::authorize('view', $post);

        $images = (new PostExporter($post))->numberedImages();
        abort_if($images === [], 404, '본문에 넣은 사진이 없습니다.');

        $path = tempnam(sys_get_temp_dir(), 'photos');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $disk = Storage::disk('uploads');
        foreach ($images as $number => $image) {
            if ($disk->exists($image->storage_key)) {
                $zip->addFile($disk->path($image->storage_key), PostExporter::filename($number));
            }
        }
        $zip->close();

        return response()->download($path, "post-{$post->id}-photos.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /** 사용자가 네이버에 직접 게시한 뒤 글 주소를 기록한다. */
    public function publish(Request $request, Post $post): PostResource
    {
        Gate::authorize('update', $post);

        $data = $request->validate([
            'published_url' => ['required', 'string', 'max:500', 'url:http,https'],
        ], ['published_url.url' => '게시한 글의 주소(https://…)를 넣어 주세요.']);

        $post->forceFill([
            'published_url' => $data['published_url'],
            'published_at' => now(),
            'status' => PostStatus::Published,
        ])->save();
        $post->publishJobs()->create([
            'publisher' => 'manual',
            'status' => 'published',
            'attempt' => 1,
            'finished_at' => now(),
        ]);

        return new PostResource($post->load(['facts', 'images']));
    }

    public function status(Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        $latest = $post->publishJobs()->latest('id')->first();

        return response()->json(['data' => [
            'status' => $post->status,
            'published_url' => $post->published_url,
            'published_at' => $post->published_at,
            'last_job' => $latest?->only(['publisher', 'status', 'attempt', 'error_message', 'finished_at']),
            'export_count' => $post->publishJobs()->where('publisher', 'manual_export')->count(),
        ]]);
    }
}
