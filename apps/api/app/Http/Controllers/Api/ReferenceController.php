<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferencesRequest;
use App\Http\Resources\ReferenceResource;
use App\Jobs\ParseReferenceJob;
use App\Models\KeywordProject;
use App\Models\ReferenceDocument;
use App\Support\ReferenceUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReferenceController extends Controller
{
    public function index(KeywordProject $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ReferenceResource::collection($project->references()->with('features')->latest('id')->get());
    }

    public function store(StoreReferencesRequest $request, KeywordProject $project): JsonResponse
    {
        Gate::authorize('update', $project);

        [$created, $skipped] = $request->has('urls')
            ? $this->storeUrls($project, $request->validated('urls'))
            : [[$this->storeText($project, $request->validated('text'), $request->validated('title'))], []];

        return response()->json([
            'data' => ReferenceResource::collection(collect($created)->each->load('features')),
            'skipped' => $skipped,
        ], 201);
    }

    /** 네이버 글처럼 서버가 가져오지 않는 참고자료에 본문을 붙여넣는다. */
    public function text(Request $request, ReferenceDocument $reference): ReferenceResource
    {
        Gate::authorize('update', $reference);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:20', 'max:100000'],
            'title' => ['nullable', 'string', 'max:200'],
        ], ['text.min' => '본문이 너무 짧습니다.']);

        Storage::disk('uploads')->put(ParseReferenceJob::textKey($reference), $data['text']);
        $reference->update([
            'title' => $data['title'] ?? $reference->title,
            'parse_status' => ParseStatus::Pending,
            'error_message' => null,
        ]);
        ParseReferenceJob::dispatch($reference);

        return new ReferenceResource($reference->load('features'));
    }

    public function reparse(ReferenceDocument $reference): ReferenceResource
    {
        Gate::authorize('update', $reference);

        // 본문은 분석 후 지우므로, 서버가 다시 가져올 수 없는 글(붙여넣기·네이버)은 다시 붙여넣어야 한다.
        $canRefetch = $reference->source_url && ! ReferenceUrl::isNaver($reference->source_url);
        if (! $canRefetch && ! Storage::disk('uploads')->exists(ParseReferenceJob::textKey($reference))) {
            throw ValidationException::withMessages(['reference' => '본문을 다시 붙여넣어 주세요.']);
        }

        $reference->update(['parse_status' => ParseStatus::Pending, 'error_message' => null]);
        ParseReferenceJob::dispatch($reference);

        return new ReferenceResource($reference->load('features'));
    }

    public function destroy(ReferenceDocument $reference): Response
    {
        Gate::authorize('delete', $reference);

        Storage::disk('uploads')->delete(array_filter([
            $reference->raw_storage_key,
            ParseReferenceJob::textKey($reference),
        ]));
        $reference->delete();

        return response()->noContent();
    }

    /**
     * @param  list<string>  $urls
     * @return array{0: list<ReferenceDocument>, 1: list<array{url: string, reason: string}>}
     */
    private function storeUrls(KeywordProject $project, array $urls): array
    {
        $existing = $project->references()->whereNotNull('source_url')->pluck('source_url')->all();
        $created = [];
        $skipped = [];

        DB::transaction(function () use ($project, $urls, &$existing, &$created, &$skipped) {
            foreach ($urls as $raw) {
                $url = ReferenceUrl::normalize($raw);
                if (in_array($url, $existing, true)) {
                    $skipped[] = ['url' => $url, 'reason' => '이미 등록된 주소입니다.'];

                    continue;
                }
                $existing[] = $url;

                $created[] = $project->references()->create([
                    'source_type' => 'user_url',
                    'source_url' => $url,
                    'usage_permission' => 'user_provided',
                    'parse_status' => ReferenceUrl::isNaver($url) ? ParseStatus::NeedsText : ParseStatus::Pending,
                ]);
            }
        });

        foreach ($created as $reference) {
            if ($reference->parse_status === ParseStatus::Pending) {
                ParseReferenceJob::dispatch($reference);
            }
        }

        return [$created, $skipped];
    }

    private function storeText(KeywordProject $project, string $text, ?string $title): ReferenceDocument
    {
        $reference = $project->references()->create([
            'source_type' => 'user_text',
            'title' => $title,
            'usage_permission' => 'user_provided',
            'parse_status' => ParseStatus::Pending,
        ]);
        Storage::disk('uploads')->put(ParseReferenceJob::textKey($reference), $text);
        ParseReferenceJob::dispatch($reference);

        return $reference;
    }
}
