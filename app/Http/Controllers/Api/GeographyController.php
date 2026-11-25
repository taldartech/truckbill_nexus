<?php

namespace App\Http\Controllers\Api;

use App\Domains\Geography\GeographyLookupService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remote searchable State/City selectors for authenticated forms.
 */
class GeographyController extends Controller
{
    public function __construct(
        private readonly GeographyLookupService $geography = new GeographyLookupService,
    ) {}

    public function states(Request $request): JsonResponse
    {
        $search = $request->string('q')->toString();

        return response()->json([
            'data' => $this->geography->listActiveStates($search !== '' ? $search : null),
        ]);
    }

    public function cities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state_id' => ['required', 'uuid', 'exists:states,id'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->geography->searchCities(
                (string) $validated['state_id'],
                isset($validated['q']) ? (string) $validated['q'] : null,
            ),
        ]);
    }
}
