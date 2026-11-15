<?php

namespace Modules\Dormitory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Dormitory\Application\Authorization\DormitoryAccess;
use Modules\Dormitory\Application\YearEnd\YearEndProcess;
use Modules\Dormitory\Domain\Facility\Dormitory;
use Modules\Dormitory\Domain\YearEnd\YearEndException;
use Modules\Dormitory\Http\Controllers\Concerns\HandlesFacilityActions;

/**
 * Proses akhir tahun asrama (PRD-008 §9, §19.2): pilih tahun ajaran → pratinjau per kamar → jalankan. Butuh
 * `dormitory.residents.manage` di node pemilik (Gate: di luar cakupan 404, tanpa izin 403).
 */
final class YearEndController
{
    use HandlesFacilityActions;

    public function show(Request $request, string $dormitory, YearEndProcess $process): Response
    {
        $target = $this->dormitory($dormitory);
        $tenantId = $this->tenantId();
        $years = $process->years($tenantId, $target);
        $chosen = (string) $request->query('tahun');
        $preview = null;
        $error = null;

        if ($chosen !== '') {
            try {
                $preview = $process->preview($tenantId, $target, $chosen);
            } catch (YearEndException $exception) {
                $error = $exception->getMessage();
            }
        }

        return Inertia::render('dormitory/year-end', [
            'dormitory' => ['id' => $target->id, 'code' => $target->code, 'name' => $target->name],
            'years' => $years,
            'chosen' => $chosen === '' ? null : $chosen,
            'preview' => $preview,
            'previewError' => $error,
            'history' => $process->history($target->id),
        ]);
    }

    public function run(Request $request, string $dormitory, YearEndProcess $process): RedirectResponse
    {
        $target = $this->dormitory($dormitory);
        $request->validate(['year' => ['required', 'string', 'max:50']], ['year.required' => 'Pilih tahun ajaran.']);

        $totals = $this->attempt(fn (): array => $process->run($this->tenantId(), $target->id, $request->string('year')->value(), $this->actorId()));

        $this->done("Proses akhir tahun selesai: {$totals['emptied']} santri diakhiri, {$totals['kept']} tetap di kamarnya.");

        return redirect()->to("/asrama/{$target->id}?tab=pengaturan");
    }

    private function dormitory(string $dormitoryId): Dormitory
    {
        $dormitory = Str::isUuid($dormitoryId) ? Dormitory::query()->find($dormitoryId) : null;

        if (! $dormitory instanceof Dormitory) {
            abort(404);
        }

        Gate::authorize(DormitoryAccess::RESIDENTS_MANAGE, $dormitory);

        return $dormitory;
    }
}
