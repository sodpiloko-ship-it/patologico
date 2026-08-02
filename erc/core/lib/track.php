<?php
/**
 * NUCLEO PATO — medicion propia (embudo server-side).
 *
 * GA4 mide visitas, pero no sabe si el pedido llego ni si se cobro, y no se le puede
 * pedir cuentas a fin de mes. El nucleo lleva su PROPIO embudo del lado del servidor:
 *   visita -> vio producto -> escribio (WhatsApp) -> pidio -> pago
 *
 * Es lo que convierte la mensualidad en algo demostrable: "esto vendiste, aqui se cayo".
 * Deliberadamente SIN cookies ni identificadores personales — solo cuenta eventos.
 */
declare(strict_types=1);

/** Etapas del embudo, en orden. */
function pato_etapas(): array
{
    return ['visita', 'producto', 'whatsapp', 'pedido', 'pago'];
}

/** Registra un evento del embudo. Barato: un contador por dia, no una fila por visita. */
function pato_evento(string $tipo, array $extra = []): bool
{
    $tipo = preg_replace('/[^a-z_]/', '', strtolower($tipo));
    if ($tipo === '') return false;
    try {
        $saved = pato_with_lock('embudo.json', function () use ($tipo) {
            $today = date('Y-m-d');
            $data = pato_read('embudo.json', []);
            if (!isset($data['dias']) || !is_array($data['dias'])) {
                $data['dias'] = [];
            }
            if (!isset($data['dias'][$today])) $data['dias'][$today] = [];
            $data['dias'][$today][$tipo] = (int) (
                $data['dias'][$today][$tipo] ?? 0
            ) + 1;
            if (count($data['dias']) > 120) {
                ksort($data['dias']);
                $data['dias'] = array_slice(
                    $data['dias'],
                    -120,
                    null,
                    true
                );
            }
            return pato_write('embudo.json', $data);
        });
    } catch (Throwable $error) {
        return false;
    }
    if (!$saved) return false;
    // Los eventos con dinero o folio si dejan rastro individual (para auditar).
    if ($extra
        && !pato_append(
            'eventos.jsonl',
            array_merge(['tipo' => $tipo], $extra)
        )) {
        return false;
    }
    return true;
}

/** Embudo agregado de los ultimos N dias. */
function pato_embudo(int $dias = 30): array
{
    $d = pato_read('embudo.json', []);
    $todos = isset($d['dias']) && is_array($d['dias']) ? $d['dias'] : [];
    $desde = date('Y-m-d', time() - $dias * 86400);
    $tot = [];
    foreach (pato_etapas() as $e) $tot[$e] = 0;
    foreach ($todos as $fecha => $conteos) {
        if ((string) $fecha < $desde) continue;
        foreach ((array) $conteos as $k => $v) {
            $tot[$k] = (int) (isset($tot[$k]) ? $tot[$k] : 0) + (int) $v;
        }
    }
    // Conversion de punta a punta: lo unico que de verdad importa.
    $visitas = max(1, (int) $tot['visita']);
    $tot['conversion_pct'] = round(((int) $tot['pedido']) / $visitas * 100, 2);
    $tot['dias'] = $dias;
    return $tot;
}

/** Donde se cae la gente: el paso con mayor caida relativa. */
function pato_fuga(int $dias = 30): array
{
    $e = pato_embudo($dias);
    $etapas = pato_etapas();
    $peor = ['de' => null, 'a' => null, 'caida_pct' => 0.0];
    for ($i = 0; $i < count($etapas) - 1; $i++) {
        $a = (int) (isset($e[$etapas[$i]]) ? $e[$etapas[$i]] : 0);
        $b = (int) (isset($e[$etapas[$i + 1]]) ? $e[$etapas[$i + 1]] : 0);
        if ($a <= 0) continue;
        $caida = ($a - $b) / $a * 100;
        if ($caida > $peor['caida_pct']) {
            $peor = ['de' => $etapas[$i], 'a' => $etapas[$i + 1], 'caida_pct' => round($caida, 1)];
        }
    }
    return $peor;
}
