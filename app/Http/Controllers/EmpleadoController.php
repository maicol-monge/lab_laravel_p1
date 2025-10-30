<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Empleado;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class EmpleadoController extends Controller
{
    // CRUD
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15);
        $query = Empleado::query();
        // By default only active employees; pass with_inactive=true to include all
        if (!$request->boolean('with_inactive')) {
            $query->where('estado', 1);
        }

        // optional filters
        if ($request->filled('departamento')) {
            $query->where('departamento', $request->departamento);
        }
        if ($request->filled('sexo')) {
            $query->where('sexo', $request->sexo);
        }

        $data = $query->paginate($perPage);
        return response()->json($data);
    }

    public function show($id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);
        if ($empleado->estado !== 1) {
            return response()->json(['message' => 'Empleado no activo'], 404);
        }
        return response()->json($empleado);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'departamento' => 'required|string|max:50',
            'puesto' => 'required|string|max:50',
            'salario_base' => 'required|numeric|min:0',
            'bonificacion' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'fecha_contratacion' => 'required|date',
            'fecha_nacimiento' => 'required|date',
            'sexo' => 'required|in:M,F,O',
            'evaluacion_desempeno' => 'nullable|numeric|min:0',
            'estado' => 'nullable|integer',
        ]);

        $empleado = Empleado::create($data);
        return response()->json($empleado, 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);

        $data = $request->validate([
            'nombre' => 'sometimes|required|string|max:100',
            'departamento' => 'sometimes|required|string|max:50',
            'puesto' => 'sometimes|required|string|max:50',
            'salario_base' => 'sometimes|required|numeric|min:0',
            'bonificacion' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'fecha_contratacion' => 'sometimes|required|date',
            'fecha_nacimiento' => 'sometimes|required|date',
            'sexo' => 'sometimes|required|in:M,F,O',
            'evaluacion_desempeno' => 'nullable|numeric|min:0',
            'estado' => 'nullable|integer',
        ]);

        $empleado->fill($data);
        $empleado->save();

        return response()->json($empleado);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);

        // By default mark estado = 0 (inactive). If force=true provided, delete the record.
        if ($request->boolean('force')) {
            $empleado->delete();
            return response()->json(['deleted' => true]);
        }

        $empleado->estado = 0;
        $empleado->save();
        return response()->json(['soft_deleted' => true]);
    }

    /**
     * Cálculos individuales por empleado (salario bruto, neto, edad, antiguedad, ratio)
     */
    public function calculos($id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);
        if ($empleado->estado !== 1) {
            return response()->json(['message' => 'Empleado no activo'], 404);
        }

        $result = [
            'id' => $empleado->{$empleado->getKeyName()},
            'nombre' => $empleado->nombre,
            'salario_base' => (float) $empleado->salario_base,
            'bonificacion' => (float) $empleado->bonificacion,
            'descuento' => (float) $empleado->descuento,
            'salario_bruto' => (float) $empleado->salario_bruto,
            'salario_neto' => (float) $empleado->salario_neto,
            'edad' => $empleado->edad,
            'antiguedad' => $empleado->antiguedad,
            'evaluacion_desempeno' => $empleado->evaluacion_desempeno === null ? null : (float) $empleado->evaluacion_desempeno,
            'ratio_desempeno_salario' => $empleado->ratio_desempeno_salario,
        ];

        return response()->json($result);
    }

    /**
     * Return many calculated statistics in one payload.
     */
    public function estadisticas(Request $request): JsonResponse
    {
        $now = Carbon::now();
        // Use only active employees (estado = 1) for all metrics
        $activo = Empleado::where('estado', 1);

        // Promedio de salario base por departamento
        $promedioPorDept = (clone $activo)->select('departamento', DB::raw('AVG(salario_base) as promedio'))
            ->groupBy('departamento')
            ->get();

        // Totales mensuales (snapshot of current values)
        $totalBonificacionesMensuales = (clone $activo)->sum('bonificacion');
        $totalDescuentosMensuales = (clone $activo)->sum('descuento');

        // Crecimiento salario neto vs año anterior (compare avg salario_neto this year vs last year based on updated_at)
        $year = $now->year;
        $avgNetoThisYear = (clone $activo)->whereYear('updated_at', $year)->get()->avg('salario_neto');
        $avgNetoLastYear = (clone $activo)->whereYear('updated_at', $year - 1)->get()->avg('salario_neto');
        $crecimientoNeto = null;
        if ($avgNetoLastYear && $avgNetoLastYear > 0) {
            $crecimientoNeto = round((($avgNetoThisYear - $avgNetoLastYear) / $avgNetoLastYear) * 100, 2);
        }

        // Edad promedio
        $edadPromedio = (clone $activo)->get()->avg('edad');

        // Distribución por sexo
        $distribucionSexo = (clone $activo)->select('sexo', DB::raw('COUNT(*) as total'))
            ->groupBy('sexo')
            ->get();

        // Edad promedio por puesto directivo vs area operativa
        // Heurística: puestos that contain directivo keywords
        $directivoKeywords = ['Director', 'Gerente', 'Jefe', 'Chief', 'CEO', 'CTO', 'CFO'];
        $directivos = (clone $activo)->where(function ($q) use ($directivoKeywords) {
            foreach ($directivoKeywords as $kw) {
                $q->orWhere('puesto', 'like', "%{$kw}%");
            }
        })->get();
        $edadPromedioDirectivo = $directivos->avg('edad');

        // Area operativa: departments with common operational names (heurística)
        $operativaKeywords = ['Operaci', 'Producci', 'Operacion', 'Taller', 'Linea'];
        $operativos = (clone $activo)->where(function ($q) use ($operativaKeywords) {
            foreach ($operativaKeywords as $kw) {
                $q->orWhere('departamento', 'like', "%{$kw}%");
            }
        })->get();
        $edadPromedioOperativo = $operativos->avg('edad');

        // Evaluación promedio por departamento
        $evaluacionPorDept = (clone $activo)->select('departamento', DB::raw('AVG(evaluacion_desempeno) as promedio'))
            ->groupBy('departamento')
            ->get();

        // Correlación salario-desempeño (Pearson)
        $salarios = (clone $activo)->whereNotNull('evaluacion_desempeno')->pluck('salario_base')->map(fn($v) => (float) $v)->toArray();
        $evals = (clone $activo)->whereNotNull('evaluacion_desempeno')->pluck('evaluacion_desempeno')->map(fn($v) => (float) $v)->toArray();
        $correlacionSalarioDesempeno = $this->pearsonCorrelation($salarios, $evals);

        // Empleados con evaluación > 95 and > 70 counts and list (limit)
        $empleadosEval95 = (clone $activo)->where('evaluacion_desempeno', '>', 95)->get();
        $personalEval70 = (clone $activo)->where('evaluacion_desempeno', '>', 70)->get();

        // Antiguedad promedio
        $antiguedadPromedio = (clone $activo)->get()->avg('antiguedad');

        // Correlación antiguedad - salario
        $antiguedades = (clone $activo)->pluck('antiguedad')->map(fn($v) => (float) $v)->toArray();
        $salariosForAnt = (clone $activo)->pluck('salario_base')->map(fn($v) => (float) $v)->toArray();
        $correlacionAntiguedadSalario = $this->pearsonCorrelation($antiguedades, $salariosForAnt);

        // Tiempo promedio de permanencia = antiguedad promedio (same as antiguedadPromedio)
        $tiempoPromedioPermanencia = $antiguedadPromedio;

        // Personal con mas de 10 años
        $personalMas10 = (clone $activo)->get()->filter(fn($e) => ($e->antiguedad ?? 0) > 10)->values();

        return response()->json([
            'promedio_salario_por_departamento' => $promedioPorDept,
            'total_bonificaciones_mensuales' => (float) $totalBonificacionesMensuales,
            'total_descuentos_mensuales' => (float) $totalDescuentosMensuales,
            'crecimiento_salario_neto_pct' => $crecimientoNeto,
            'edad_promedio' => $edadPromedio,
            'distribucion_sexo' => $distribucionSexo,
            'edad_promedio_directivo' => $edadPromedioDirectivo,
            'edad_promedio_operativo' => $edadPromedioOperativo,
            'evaluacion_promedio_por_departamento' => $evaluacionPorDept,
            'correlacion_salario_desempeno' => $correlacionSalarioDesempeno,
            'empleados_con_eval_gt_95' => $empleadosEval95,
            'personal_eval_gt_70' => $personalEval70,
            'antiguedad_promedio' => $antiguedadPromedio,
            'correlacion_antiguedad_salario' => $correlacionAntiguedadSalario,
            'tiempo_promedio_permanencia' => $tiempoPromedioPermanencia,
            'personal_mas_10_anos' => $personalMas10,
        ]);
    }

    /**
     * Pearson correlation helper. Returns null when not enough data.
     * x and y should be arrays of numbers of same length.
     */
    private function pearsonCorrelation(array $x, array $y)
    {
        $n = min(count($x), count($y));
        if ($n < 2)
            return null;

        // truncate to same length
        $x = array_slice($x, 0, $n);
        $y = array_slice($y, 0, $n);

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $num = 0.0;
        $denX = 0.0;
        $denY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $num += $dx * $dy;
            $denX += $dx * $dx;
            $denY += $dy * $dy;
        }

        $den = sqrt($denX * $denY);
        if ($den == 0.0)
            return null;
        return round($num / $den, 4);
    }
}
