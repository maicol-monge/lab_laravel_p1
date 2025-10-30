<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Empleado extends Model
{
    // Table & PK (migration uses id_empleado)
    protected $table = 'empleados';
    protected $primaryKey = 'id_empleado';
    public $incrementing = true;
    protected $keyType = 'int';

    // Mass assignable
    protected $fillable = [
        'nombre',
        'departamento',
        'puesto',
        'salario_base',
        'bonificacion',
        'descuento',
        'fecha_contratacion',
        'fecha_nacimiento',
        'sexo',
        'evaluacion_desempeno',
        'estado',
    ];

    // Casts
    protected $casts = [
        'salario_base' => 'float',
        'bonificacion' => 'float',
        'descuento' => 'float',
        'evaluacion_desempeno' => 'float',
        'estado' => 'integer',
    ];

    protected $dates = [
        'fecha_contratacion',
        'fecha_nacimiento',
        'created_at',
        'updated_at',
    ];

    // Append computed attributes to JSON
    protected $appends = [
        'salario_bruto',
        'salario_neto',
        'edad',
        'antiguedad',
        'ratio_desempeno_salario',
    ];

    // Computed attributes
    public function getSalarioBrutoAttribute()
    {
        $base = $this->salario_base ?? 0.0;
        $bon = $this->bonificacion ?? 0.0;
        return round($base + $bon, 2);
    }

    public function getSalarioNetoAttribute()
    {
        $bruto = $this->salario_bruto ?? (($this->salario_base ?? 0.0) + ($this->bonificacion ?? 0.0));
        $descuento = $this->descuento ?? 0.0;
        return round($bruto - $descuento, 2);
    }

    public function getEdadAttribute()
    {
        if (!$this->fecha_nacimiento)
            return null;
        return Carbon::parse($this->fecha_nacimiento)->age;
    }

    public function getAntiguedadAttribute()
    {
        if (!$this->fecha_contratacion)
            return null;
        return Carbon::parse($this->fecha_contratacion)->diffInYears(Carbon::now());
    }

    public function getRatioDesempenoSalarioAttribute()
    {
        $salario = $this->salario_base ?? 0.0;
        $eval = $this->evaluacion_desempeno ?? null;
        if (!$eval || $salario == 0.0)
            return null;
        return round($eval / $salario, 6);
    }
}
