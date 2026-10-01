<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentHotel;
use App\Services\HBX\ContentReferenceStore;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use JsonException;

class ContentHotelController extends Controller
{
    public function index(Request $request, ContentReferenceStore $references): View
    {
        $language = $this->language($request);
        $term = trim((string) $request->query('q', ''));

        $hotels = $this->listQuery($language)
            ->when($term !== '', function (Builder $query) use ($term, $language): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(function (Builder $inner) use ($term, $language, $like): void {
                    $name = function (Builder $translations) use ($like, $language): void {
                        $translations->where('language', $language)->where('name', 'like', $like);
                    };

                    if (preg_match('/^\d{1,10}$/', $term) === 1) {
                        $inner->where('hbx_hotel_code', (int) $term)->orWhereHas('translations', $name);

                        return;
                    }

                    $inner->whereHas('translations', $name);
                });
            })
            ->orderBy('hbx_hotel_code')
            ->paginate(25)
            ->withQueryString();

        return view('content.hotels.index', [
            'hotels' => $hotels,
            'language' => $language,
            'term' => $term,
            'categoryLabels' => $references->descriptionsForCodes(
                'categories',
                $hotels->getCollection()->pluck('category_code')->filter()->all(),
                $language,
            ),
        ]);
    }

    public function show(Request $request, int $hotelCode, ContentReferenceStore $references): View
    {
        $language = $this->language($request);
        $hotel = $this->hotel($hotelCode);
        $hotel->load([
            'translations',
            'snapshots' => fn ($query) => $query->select($this->snapshotColumns()),
            'phones',
            'boards',
            'segments',
            'facilities',
            'images',
            'terminals',
            'interestPoints.translations' => fn ($query) => $query->where('language', $language),
            'rooms.translations' => fn ($query) => $query->where('language', $language),
            'rooms.facilities',
            'rooms.stays.facilities',
        ]);

        return view('content.hotels.show', [
            'hotel' => $hotel,
            'language' => $language,
            'translation' => $hotel->translations->firstWhere('language', $language),
            'snapshot' => $hotel->snapshots->firstWhere('language', $language),
            'labels' => $references->labelsForHotel($hotel, $language),
        ]);
    }

    public function snapshot(Request $request, int $hotelCode): View
    {
        $language = $this->language($request);
        $hotel = $this->hotel($hotelCode);
        $snapshot = $hotel->snapshots()->where('language', $language)->firstOrFail();

        return view('developer.content-snapshot', [
            'hotel' => $hotel,
            'snapshot' => $snapshot,
            'language' => $language,
            'pretty' => $this->prettyJson($snapshot->raw_payload),
        ]);
    }

    private function listQuery(string $language): Builder
    {
        return ContentHotel::query()
            ->select([
                'id',
                'hbx_hotel_code',
                'country_code',
                'destination_code',
                'category_code',
                'ranking',
                'supplier_last_update',
                'updated_at',
            ])
            ->with([
                'translations' => fn ($query) => $query->select([
                    'id',
                    'content_hotel_id',
                    'language',
                    'name',
                    'city',
                ])->where('language', $language),
                'snapshots' => fn ($query) => $query->select([
                    'id',
                    'content_hotel_id',
                    'language',
                    'content_origin',
                ])->where('language', $language),
            ]);
    }

    private function hotel(int $hotelCode): ContentHotel
    {
        return ContentHotel::query()->where('hbx_hotel_code', $hotelCode)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function snapshotColumns(): array
    {
        return [
            'id',
            'content_hotel_id',
            'language',
            'content_hash',
            'payload_bytes',
            'content_origin',
            'content_synced_at',
            'updated_at',
        ];
    }

    private function language(Request $request): string
    {
        $language = strtoupper(trim((string) $request->query('language', 'ENG')));

        return preg_match('/^[A-Z]{3}$/', $language) === 1 ? $language : 'ENG';
    }

    private function prettyJson(string $raw): string
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $raw;
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($pretty) ? $pretty : $raw;
    }
}
