<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Empleado;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

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
            'dui' => ['required', 'regex:/^\d{8}-\d$/', 'unique:empleados,dui'],
            'telefono' => ['required', 'regex:/^[0-9\-\+\s]{7,20}$/', 'unique:empleados,telefono'],
            'correo' => ['required', 'email', 'max:100', 'unique:empleados,correo'],
            'salario_base' => 'required|numeric|min:0',
            'bonificacion' => 'nullable|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
            'fecha_contratacion' => 'required|date',
            'fecha_nacimiento' => 'required|date',
            'sexo' => 'required|in:M,F,O',
            'evaluacion_desempeno' => 'nullable|numeric|min:0',
            'estado' => 'nullable|integer',
        ]);

        // business validations
        $base = isset($data['salario_base']) ? (float) $data['salario_base'] : 0.0;
        $bon = isset($data['bonificacion']) ? (float) $data['bonificacion'] : 0.0;
        $descuento = isset($data['descuento']) ? (float) $data['descuento'] : 0.0;

        // edad >= 18
        $edad = Carbon::parse($data['fecha_nacimiento'])->diffInYears(Carbon::now());
        if ($edad < 18) {
            return response()->json(['message' => 'El empleado debe ser mayor de edad'], 422);
        }

        // fecha_nacimiento <= fecha_contratacion
        if (Carbon::parse($data['fecha_nacimiento'])->gt(Carbon::parse($data['fecha_contratacion']))) {
            return response()->json(['message' => 'La fecha de nacimiento no puede ser posterior a la fecha de contratación'], 422);
        }

        // descuento no mayor que salario bruto
        $bruto = $base + $bon;
        if ($descuento > $bruto) {
            return response()->json(['message' => 'El descuento no puede ser mayor al salario bruto'], 422);
        }

        $empleado = Empleado::create($data);
        return response()->json($empleado, 201);
    }


    public function update(Request $request, $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);
        // Build rules so we can ignore the current record on unique checks
        $rules = [
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
        ];

        // Unique contact fields: ignore current empleado by primary key
        $rules['dui'] = [
            'sometimes',
            'required',
            'regex:/^\d{8}-\d$/',
            Rule::unique('empleados', 'dui')->ignore($empleado->getKey(), $empleado->getKeyName()),
        ];
        $rules['telefono'] = [
            'sometimes',
            'required',
            'regex:/^[0-9\-\+\s]{7,20}$/',
            Rule::unique('empleados', 'telefono')->ignore($empleado->getKey(), $empleado->getKeyName()),
        ];
        $rules['correo'] = [
            'sometimes',
            'required',
            'email',
            'max:100',
            Rule::unique('empleados', 'correo')->ignore($empleado->getKey(), $empleado->getKeyName()),
        ];

        $data = $request->validate($rules);

        // Merge current values to validate business rules against final values
        $final = array_merge($empleado->toArray(), $data);

        $base = isset($final['salario_base']) ? (float) $final['salario_base'] : 0.0;
        $bon = isset($final['bonificacion']) ? (float) $final['bonificacion'] : 0.0;
        $descuento = isset($final['descuento']) ? (float) $final['descuento'] : 0.0;

        // edad >= 18 (if fecha_nacimiento provided or exists)
        if (!empty($final['fecha_nacimiento'])) {
            $edad = Carbon::parse($final['fecha_nacimiento'])->diffInYears(Carbon::now());
            if ($edad < 18) {
                return response()->json(['message' => 'El empleado debe ser mayor de edad'], 422);
            }
        }

        // fecha_nacimiento <= fecha_contratacion (if both present)
        if (!empty($final['fecha_nacimiento']) && !empty($final['fecha_contratacion'])) {
            if (Carbon::parse($final['fecha_nacimiento'])->gt(Carbon::parse($final['fecha_contratacion']))) {
                return response()->json(['message' => 'La fecha de nacimiento no puede ser posterior a la fecha de contratación'], 422);
            }
        }

        // descuento no mayor que salario bruto
        $bruto = $base + $bon;
        if ($descuento > $bruto) {
            return response()->json(['message' => 'El descuento no puede ser mayor al salario bruto'], 422);
        }

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
            'dui' => $empleado->dui,
            'telefono' => $empleado->telefono,
            'correo' => $empleado->correo,
            'fecha_contratacion' => $empleado->fecha_contratacion,
            'salario_base' => round((float) $empleado->salario_base, 2),
            'bonificacion' => round((float) $empleado->bonificacion, 2),
            'descuento' => round((float) $empleado->descuento, 2),
            'salario_bruto' => round((float) $empleado->salario_bruto, 2),
            'salario_neto' => round((float) $empleado->salario_neto, 2),
            'edad' => $empleado->edad,
            'antiguedad' => $empleado->antiguedad,
            'evaluacion_desempeno' => $empleado->evaluacion_desempeno === null ? null : (float) $empleado->evaluacion_desempeno,
            'ratio_desempeno_salario' => $empleado->ratio_desempeno_salario === null ? null : round($empleado->ratio_desempeno_salario, 2),
        ];

        // business check: descuento no mayor que salario bruto
        if ($result['descuento'] > $result['salario_bruto']) {
            return response()->json(['message' => 'El descuento en la ficha del empleado es mayor que el salario bruto'], 422);
        }

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
            ->get()
            ->map(fn($r) => [
                'departamento' => $r->departamento,
                'promedio' => round((float) $r->promedio, 2),
            ]);

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
        $edadPromedio = round((clone $activo)->get()->avg('edad') ?? 0, 2);

        // Distribución por sexo
        $distribucionSexo = (clone $activo)->select('sexo', DB::raw('COUNT(*) as total'))
            ->groupBy('sexo')
            ->get();

        // Edad promedio por puesto directivo vs area operativa
        // Use known puestos/departamentos supplied by frontend constants
        $puestosDirectivos = [
            // from frontend PUESTOS: consider 'Gerente' as directivo
            'Gerente',
        ];

        $departamentosOperativos = [
            // from frontend DEPARTAMENTOS: consider 'Operaciones' and 'TI' as operativa
            'Operaciones',
            'TI',
        ];

        // Directivos: exact match on puesto
        $directivos = (clone $activo)->whereIn('puesto', $puestosDirectivos)->get();
        $edadPromedioDirectivo = round($directivos->avg('edad') ?? 0, 2);

        // Operativos: departamento in known operativa list
        $operativos = (clone $activo)->whereIn('departamento', $departamentosOperativos)->get();
        $edadPromedioOperativo = round($operativos->avg('edad') ?? 0, 2);

        // Evaluación promedio por departamento
        $evaluacionPorDept = (clone $activo)->select('departamento', DB::raw('AVG(evaluacion_desempeno) as promedio'))
            ->groupBy('departamento')
            ->get()
            ->map(fn($r) => [
                'departamento' => $r->departamento,
                'promedio' => round((float) $r->promedio, 2),
            ]);

        // Correlación salario-desempeño (Pearson)
        // Get same ordered rows to keep salary and evals aligned
        $rowsEval = (clone $activo)->whereNotNull('evaluacion_desempeno')->get();
        $salarios = $rowsEval->pluck('salario_base')->map(fn($v) => (float) $v)->toArray();
        $evals = $rowsEval->pluck('evaluacion_desempeno')->map(fn($v) => (float) $v)->toArray();
        $correlacionSalarioDesempeno = $this->pearsonCorrelation($salarios, $evals);

        // Empleados con evaluación > 95 and > 70 counts and list (limit)
        $empleadosEval95 = (clone $activo)->where('evaluacion_desempeno', '>', 95)->get()->map(fn($e) => [
            'id' => $e->{$e->getKeyName()},
            'nombre' => $e->nombre,
            'evaluacion_desempeno' => (float) $e->evaluacion_desempeno,
        ]);
        $personalEval70 = (clone $activo)->where('evaluacion_desempeno', '>', 70)->get()->map(fn($e) => [
            'id' => $e->{$e->getKeyName()},
            'nombre' => $e->nombre,
            'evaluacion_desempeno' => (float) $e->evaluacion_desempeno,
        ]);

        // Antiguedad promedio
        $antiguedadPromedio = round((clone $activo)->get()->avg('antiguedad') ?? 0, 2);

        // Correlación antiguedad - salario
        $rowsAll = (clone $activo)->get();
        $antiguedades = $rowsAll->pluck('antiguedad')->map(fn($v) => (float) $v)->toArray();
        $salariosForAnt = $rowsAll->pluck('salario_base')->map(fn($v) => (float) $v)->toArray();
        $correlacionAntiguedadSalario = $this->pearsonCorrelation($antiguedades, $salariosForAnt);

        // Tiempo promedio de permanencia = antiguedad promedio (same as antiguedadPromedio)
        $tiempoPromedioPermanencia = $antiguedadPromedio;

        // Personal con mas de 10 años
        $personalMas10 = (clone $activo)->get()->filter(fn($e) => ($e->antiguedad ?? 0) > 10)->values()->map(fn($e) => [
            'id' => $e->{$e->getKeyName()},
            'nombre' => $e->nombre,
            'antiguedad' => $e->antiguedad,
        ]);

        return response()->json([
            'promedio_salario_por_departamento' => $promedioPorDept,
            'total_bonificaciones_mensuales' => round((float) $totalBonificacionesMensuales, 2),
            'total_descuentos_mensuales' => round((float) $totalDescuentosMensuales, 2),
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
        return round($num / $den, 2);
    }
}
