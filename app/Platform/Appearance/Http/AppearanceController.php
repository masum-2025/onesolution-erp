<?php

namespace App\Platform\Appearance\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * "My look": the person's own template, highlight color and readability
 * settings, kept on the identity. What applies in an organization (where a
 * lock above may win) comes with /api/me, which the app reloads after this.
 */
class AppearanceController extends Controller
{
    public function update(AppearanceRequest $request): JsonResponse
    {
        $user = $request->user();
        $preferences = array_filter(
            array_replace($user->ui_preferences ?? [], $request->validated()),
            fn ($value) => $value !== null,
        );

        $user->forceFill(['ui_preferences' => $preferences === [] ? null : $preferences])->save();

        return response()->json(['data' => (object) $preferences, 'message' => __('identity.appearance.saved')]);
    }
}
