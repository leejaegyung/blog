<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostImageRequest;
use App\Http\Resources\PostImageResource;
use App\Jobs\AnalyzeImagesJob;
use App\Models\Post;
use App\Models\PostImage;
use App\Services\AiWorker\AiWorkerClient;
use App\Services\AiWorker\AiWorkerUnavailableException;
use App\Services\AiWorker\InvalidImageException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PostImageController extends Controller
{
    public function store(StorePostImageRequest $request, Post $post, AiWorkerClient $worker): PostImageResource
    {
        Gate::authorize('update', $post);

        $file = $request->file('image');
        $disk = Storage::disk('uploads');
        $temp = $file->storeAs('tmp', Str::uuid().'.'.($file->guessExtension() ?? 'bin'), 'uploads');
        // 사용자별 prefix (기획서 20장)
        $prefix = "users/{$post->user_id}/posts/{$post->id}/".Str::uuid();

        try {
            $result = $worker->processImage($temp, $prefix, $request->boolean('strip_exif', true));
        } catch (InvalidImageException) {
            throw ValidationException::withMessages(['image' => '이미지로 읽을 수 없는 파일입니다.']);
        } catch (AiWorkerUnavailableException $e) {
            report($e);
            throw new ServiceUnavailableHttpException(message: '사진 처리 서비스에 연결할 수 없습니다. 잠시 뒤 다시 시도해 주세요.');
        } finally {
            $disk->delete($temp);
        }

        $image = $post->images()->create([
            'storage_key' => $result['storage_key'],
            'thumb_key' => $result['thumb_key'],
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $result['mime_type'],
            'width' => $result['width'],
            'height' => $result['height'],
            'size_bytes' => $result['size_bytes'],
            'taken_at' => $result['taken_at'],
            'sort_order' => ($post->images()->max('sort_order') ?? -1) + 1,
        ]);

        return new PostImageResource($image);
    }

    /** 아직 분석하지 않은(또는 force면 전부) 사진을 분석한다. 이미 분석 중인 사진은 건너뛴다. */
    public function analyze(Request $request, Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        $images = $post->images()
            ->where(fn ($q) => $q->whereNull('vision_status')->orWhere('vision_status', '!=', 'pending'))
            ->when(! $request->boolean('force'), fn ($q) => $q->where(fn ($q) => $q->whereNull('vision_json')))
            ->get();

        if ($images->isNotEmpty()) {
            $post->images()->whereKey($images->modelKeys())->update(['vision_status' => 'pending', 'vision_error' => null]);
            AnalyzeImagesJob::dispatch($post, $images->modelKeys(), $request->boolean('force'));
        }

        return response()->json([
            'data' => PostImageResource::collection($post->images()->get()),
            'queued' => $images->count(),
        ], $images->isNotEmpty() ? 202 : 200);
    }

    public function destroy(Post $post, PostImage $image): Response
    {
        Gate::authorize('update', $post);

        Storage::disk('uploads')->delete(array_filter([$image->storage_key, $image->thumb_key]));
        $image->delete();

        return response()->noContent();
    }

    public function reorder(Request $request, Post $post): AnonymousResourceCollection
    {
        Gate::authorize('update', $post);

        $current = $post->images()->pluck('id')->sort()->values()->all();
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        // 일부만 보내거나 다른 글의 사진이 섞이면 순서가 꼬이므로 전체 목록을 요구한다.
        if (collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all() !== $current) {
            throw ValidationException::withMessages(['ids' => '이 글의 사진 전체를 한 번씩 보내야 합니다.']);
        }

        DB::transaction(function () use ($post, $ids) {
            foreach (array_values($ids) as $order => $id) {
                $post->images()->whereKey($id)->update(['sort_order' => $order]);
            }
        });

        return PostImageResource::collection($post->images()->get());
    }

    public function file(Request $request, Post $post, PostImage $image): BinaryFileResponse
    {
        Gate::authorize('view', $post);

        $key = $request->query('variant') === 'thumb' && $image->thumb_key ? $image->thumb_key : $image->storage_key;
        $disk = Storage::disk('uploads');
        abort_unless($disk->exists($key), 404);

        return response()->file($disk->path($key), [
            'Content-Type' => $image->mime_type ?? 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
