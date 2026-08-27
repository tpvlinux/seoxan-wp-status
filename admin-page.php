<?php
if (!defined('ABSPATH')) exit;

function seoxan_status_color($value)
{
    return "<span class='seoxan-status-badge seoxan-$value'>$value</span>";
}

function seoxan_wp_status_page()
{
    // Datos
    $autoload = seoxan_get_autoload_size();
    $autoload_top = seoxan_get_autoload_top();
    $transients = seoxan_get_transients_count();
    $sessions = seoxan_get_wc_sessions();
    $redis = seoxan_get_redis_info();
    $tables = seoxan_get_table_sizes();

    // Score inicial
    $score = 100;

    // Evaluación autoload
    if ($autoload < 500000) {
        $autoload_status = 'ok';
    } elseif ($autoload < 2000000) {
        $autoload_status = 'warn';
        $score -= 10;
    } else {
        $autoload_status = 'bad';
        $score -= 25;
    }

    // Transients
    if ($transients < 2000) {
        $trans_status = 'ok';
    } elseif ($transients < 5000) {
        $trans_status = 'warn';
        $score -= 5;
    } else {
        $trans_status = 'bad';
        $score -= 15;
    }

    // Sesiones WooCommerce
    if ($sessions < 100) {
        $sess_status = 'ok';
    } else {
        $sess_status = 'warn';
        $score -= 5;
    }

    // Redis
    if ($redis && $redis['hits'] > 100) {
        $redis_status = 'ok';
    } elseif ($redis) {
        $redis_status = 'warn';
        $score -= 5;
    } else {
        $redis_status = 'bad';
        $score -= 15;
    }

    // Estado global
    if ($score >= 85) $global_status = "🟢 Tu WordPress está en muy buen estado";
    elseif ($score >= 60) $global_status = "🟡 Rendimiento bueno, pero con margen de mejora";
    else $global_status = "🔴 Rendimiento pobre: se recomienda optimización";

?>
    <div class="wrap seoxan-status">
        <h1>Seoxan WP Status</h1>

        <div class="seoxan-score-box">
            <h2><?= $global_status ?></h2>
            <div class="seoxan-score">Seoxan Score: <strong><?= $score ?>/100</strong></div>
        </div>

        <h2>📊 Resumen del Sistema</h2>

        <table class="widefat">
            <tr>
                <th>Autoload</th>
                <td><?= seoxan_status_color($autoload_status) ?> (<?= number_format($autoload) ?> bytes)</td>
            </tr>
            <tr>
                <th>Transients</th>
                <td><?= seoxan_status_color($trans_status) ?> (<?= $transients ?> encontrados)</td>
            </tr>
            <tr>
                <th>WooCommerce Sessions</th>
                <td><?= seoxan_status_color($sess_status) ?> (<?= $sessions ?> activas)</td>
            </tr>
            <tr>
                <th>Redis</th>
                <td><?= seoxan_status_color($redis_status) ?> (HITS: <?= $redis['hits'] ?? '0' ?>)</td>
            </tr>
        </table>

        <div class="seoxan-api-teaser">
            <p>
                🔑 <strong>API remota:</strong> consulta el estado de actualizaciones de este WordPress desde
                un servicio externo.
                <a href="<?= esc_url(admin_url('admin.php?page=seoxan-wp-status-api')) ?>">
                    <?= seoxan_has_api_key() ? 'Gestionar la API Key →' : 'Activar la API →' ?>
                </a>
            </p>
        </div>

        <p>
            <button id="seoxan-show-details" class="button button-primary">
                Ver detalles técnicos
            </button>
        </p>

        <!-- SECCIÓN OCULTA -->
        <div id="seoxan-tech-details" style="display:none; margin-top:20px;">

            <h2>🔧 Detalles Técnicos</h2>

            <h3>Top Autoload</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Option</th>
                        <th>Tamaño</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($autoload_top as $row): ?>
                        <tr>
                            <td><?= esc_html($row->option_name) ?></td>
                            <td><?= number_format($row->size) ?> bytes</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Tablas MySQL</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Tabla</th>
                        <th>Tamaño</th>
                        <th>Filas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables as $t): ?>
                        <tr>
                            <td><?= $t->Name ?></td>
                            <td><?= number_format($t->Data_length + $t->Index_length) ?> bytes</td>
                            <td><?= $t->Rows ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        </div>

    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const btn = document.getElementById("seoxan-show-details");
            const panel = document.getElementById("seoxan-tech-details");

            btn.addEventListener("click", function() {
                if (panel.style.display === "none") {
                    panel.style.display = "block";
                    btn.textContent = "Ocultar detalles técnicos";
                } else {
                    panel.style.display = "none";
                    btn.textContent = "Ver detalles técnicos";
                }
            });
        });
    </script>

<?php

    echo "<h2>SQL recomendado para limpieza (no se ejecuta automáticamente)</h2>";

    $sql = seoxan_generate_cleanup_sql();

    echo "<textarea style='width:100%;height:300px;font-family:monospace;'>$sql</textarea>";

    echo "<p><strong>IMPORTANTE:</strong> Copia este SQL, pégalo en ChatGPT si quieres validarlo y después puedes ejecutarlo manualmente en phpMyAdmin o un cliente MySQL.</p>";
}
