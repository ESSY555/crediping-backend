<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Run pending database migrations from a browser when SSH/CLI is unavailable.
 * Protected by MIGRATION_TOKEN; disabled when that env value is empty.
 */
class MigrateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $expected = (string) (config('app.migration_token') ?: getenv('MIGRATION_TOKEN') ?: '');
        $given = $this->tokenFromRequest($request);

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            abort(404);
        }

        Artisan::call('migrate', ['--force' => true]);
        $migrate = trim(Artisan::output());

        Artisan::call('migrate:status');
        $status = trim(Artisan::output());

        $body = e($migrate === '' ? 'No artisan output.' : $migrate)
            . "\n\n"
            . e($status);

        return response('<pre>' . $body . '</pre>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function tokenFromRequest(Request $request): string
    {
        if ($request->filled('token')) {
            return (string) $request->query('token');
        }

        $routeToken = (string) $request->route('token', '');
        if ($routeToken !== '') {
            return $routeToken;
        }

        $query = $request->query();
        if (count($query) === 1) {
            $key = (string) array_key_first($query);
            $value = $query[$key];

            return ($value === '' || $value === null) ? $key : (string) $value;
        }

        return '';
    }
}
