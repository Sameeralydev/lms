<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Appearance\ThemeRegistry;
use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(array_keys(ThemeRegistry::all()))],
            'return_to' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (
                        ! is_string($value)
                        || str_starts_with($value, '//')
                        || str_contains($value, '\\')
                        || str_contains($value, "\r")
                        || str_contains($value, "\n")
                    ) {
                        $fail('Choose a valid workspace page to return to.');

                        return;
                    }

                    $path = parse_url($value, PHP_URL_PATH);
                    $isWorkspacePath = is_string($path)
                        && ($path === '/workspaces' || str_starts_with($path, '/platform/') || preg_match('#^/t/[a-z0-9-]+(?:/|$)#i', $path));

                    if (! str_starts_with($value, '/') || ! $isWorkspacePath) {
                        $fail('Choose a valid workspace page to return to.');
                    }
                },
            ],
        ]);
        UserPreference::query()->updateOrCreate(['user_id' => $request->user()->getKey(), 'key' => 'theme'], ['value' => ['theme' => $data['theme']]]);

        return redirect()->to($data['return_to'] ?? '/workspaces')->with('status', 'Your theme preference is saved.');
    }
}
