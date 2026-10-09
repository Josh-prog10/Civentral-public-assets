<?php
declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AssetService.php';

use App\Service\AssetService;

$assetService = new AssetService($pdo);

if (!$isSuperAdmin && !$hasResourceAccess(['assets_manage', 'asset', 'asset management', 'asset inventory', 'asset inventory tracker'])) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

$categories = $assetService->getCategories();
$message = $error = '';
$success = false;
$newAssetId = 0;

// --- CSRF token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid request.';
    } else {
        $data = [
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'plate_number' => trim($_POST['plate_number'] ?? ''),
            'location_text' => trim($_POST['location_text'] ?? ''),
            'latitude' => isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null,
            'longitude' => isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null,
            'condition' => $_POST['condition'] ?? 'good',
            'acquisition_date' => $_POST['acquisition_date'] ?: null,
            'lifecycle_status' => $_POST['lifecycle_status'] ?? 'active',
            'last_maintenance_date' => $_POST['last_maintenance_date'] ?: null,
            'next_maintenance_date' => $_POST['next_maintenance_date'] ?: null,
            'maintenance_interval_months' => (int)($_POST['maintenance_interval_months'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
            'created_by' => $headerUser['id'],
            'updated_by' => $headerUser['id']
        ];

        if (empty($data['category_id']) || empty($data['name']) || empty($data['location_text'])) {
            $error = "Category, Name, and Location are required.";
        } else {
            try {
                if ($assetService->addAsset($data)) {
                    $newAssetId = (int)$pdo->lastInsertId();
                    $success = true;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $error = '';
                    $message = 'Asset added successfully!';
                } else {
                    $error = "Failed to add asset.";
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Add Asset';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    #map { height: 320px; border-radius: 0.5rem; border: 1px solid #cbd5e1; }
    .dark #map { border-color: #334155; }
</style>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-3xl mx-auto">
        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight mb-6">
            <i class="fa-solid fa-plus-circle text-brand-medium mr-3"></i>Add New Asset
        </h1>

        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 rounded-lg text-sm font-bold">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success && $newAssetId > 0): ?>
            <div class="mb-6 p-4 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                <p class="font-bold text-sm mb-3"><i class="fa-solid fa-check-circle mr-2"></i> <?= htmlspecialchars($message) ?></p>
                <div class="flex flex-wrap gap-3">
                    <a href="<?= $basePath ?>pages/assets/views.php?id=<?= $newAssetId ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-lg transition shadow-sm">
                        <i class="fa-solid fa-eye mr-1"></i> View Asset
                    </a>
                    <a href="<?= $basePath ?>pages/assets/adb.php" class="px-4 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-sm rounded-lg hover:bg-slate-300 dark:hover:bg-slate-600 transition">
                        <i class="fa-solid fa-arrow-left mr-1"></i> Asset Dashboard
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
            <form method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <!-- Category -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Category *</label>
                    <select name="category_id" id="category_id" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">Select category</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Name -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Asset Name *</label>
                    <input type="text" name="name" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="e.g. Laptop #123">
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="Optional details"></textarea>
                </div>

                <!-- Plate Number (conditional) -->
                <div id="field-plate_number" class="hidden">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">License Plate (for vehicles)</label>
                    <input type="text" name="plate_number" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="e.g. ABC-1234">
                </div>

                <!-- Location -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Location *</label>
                    <input type="text" name="location_text" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="e.g. Building A, Room 202">
                </div>

                <!-- Coordinates (conditional - lat/lng inputs) -->
                <div id="field-coordinates">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Coordinates (optional)</label>
                    <div class="grid grid-cols-2 gap-3">
                        <input type="number" step="any" name="latitude" id="latitude" class="px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="Latitude" readonly>
                        <input type="number" step="any" name="longitude" id="longitude" class="px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="Longitude" readonly>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Click on the map below to pin a location. Only Caloocan City is allowed.</p>
                </div>

                <!-- GIS Map (conditional - the map widget itself) -->
                <div id="field-gis_map">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Pin Location on Map <span class="text-xs font-normal text-slate-500">(inside Caloocan City only)</span></label>
                    <div id="map"></div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Click on the map to set coordinates. Only locations inside Caloocan City are allowed.</p>
                    <div id="map-warning" class="hidden mt-2 p-2 text-xs font-bold rounded-lg bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300 border border-rose-200 dark:border-rose-800"></div>
                </div>

                <!-- Condition -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Condition</label>
                    <select name="condition" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="good">Good</option>
                        <option value="fair">Fair</option>
                        <option value="poor">Poor</option>
                        <option value="damaged">Damaged</option>
                        <option value="under_repair">Under Repair</option>
                    </select>
                </div>

                <!-- Lifecycle Status -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Lifecycle Status</label>
                    <select name="lifecycle_status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="active">Active</option>
                        <option value="retired">Retired</option>
                        <option value="disposed">Disposed</option>
                    </select>
                </div>

                <!-- Acquisition Date (conditional) -->
                <div id="field-acquisition_date">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Acquisition Date</label>
                    <input type="date" name="acquisition_date" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                </div>

                <!-- Maintenance (conditional) -->
                <div id="field-maintenance" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Last Maintenance Date</label>
                            <input type="date" name="last_maintenance_date" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Next Maintenance Date</label>
                            <input type="date" name="next_maintenance_date" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Maintenance Interval (months)</label>
                        <input type="number" name="maintenance_interval_months" min="0" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="0 = none">
                    </div>
                </div>

                <!-- Notes (conditional) -->
                <div id="field-notes">
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Notes</label>
                    <textarea name="notes" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="Any additional information"></textarea>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="px-6 py-2 bg-brand-dark hover:bg-[#0e4f62] text-white font-bold text-sm rounded-lg transition shadow-sm">
                        <i class="fa-solid fa-check mr-1"></i> Add Asset
                    </button>
                    <a href="<?= $basePath ?>pages/assets/adb.php" class="px-6 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-sm rounded-lg hover:bg-slate-300 dark:hover:bg-slate-600 transition">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Per-category field permissions, injected from PHP (category_id => config)
    var CATEGORY_FIELD_CONFIG = <?= json_encode(array_column($categories, 'field_config_parsed', 'id')) ?>;
    var DEFAULT_FIELD_CONFIG = {
        plate_number: false,
        coordinates: true,
        gis_map: true,
        maintenance: true,
        acquisition_date: true,
        notes: true
    };

    // ---------- Caloocan City Boundary (from cemeteries.php) ----------
    var CALOOCAN_POLYGONS = [
        // South Caloocan
        [
            [14.6140, 120.9550], [14.6130, 120.9720], [14.6180, 120.9950],
            [14.6350, 121.0030], [14.6550, 121.0050], [14.6700, 121.0000],
            [14.6880, 120.9870], [14.6900, 120.9700], [14.6800, 120.9550],
            [14.6600, 120.9500], [14.6400, 120.9520]
        ],
        // North Caloocan
        [
            [14.7050, 120.9750], [14.6980, 121.0000], [14.7100, 121.0200],
            [14.7300, 121.0350], [14.7500, 121.0600], [14.7700, 121.0750],
            [14.7850, 121.0700], [14.7900, 121.0500], [14.7850, 121.0200],
            [14.7750, 120.9900], [14.7600, 120.9700], [14.7400, 120.9650],
            [14.7200, 120.9680]
        ]
    ];

    function pointInPolygon(lat, lng, polygon) {
        var inside = false;
        for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            var xi = polygon[i][1], yi = polygon[i][0];
            var xj = polygon[j][1], yj = polygon[j][0];
            var intersect = ((yi > lat) !== (yj > lat)) &&
                (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }

    function isInCaloocan(lat, lng) {
        for (var i = 0; i < CALOOCAN_POLYGONS.length; i++) {
            if (pointInPolygon(lat, lng, CALOOCAN_POLYGONS[i])) {
                return true;
            }
        }
        return false;
    }

    // Monumento, Caloocan
    var CALOOCAN_CENTER = [14.6572, 120.9837];

    document.addEventListener('DOMContentLoaded', function() {
        // ---- Map setup (same as cemeteries.php) ----
        var map = L.map('map', {
            maxBounds: [[14.55, 120.90], [14.85, 121.13]],
            maxBoundsViscosity: 0.8,
            minZoom: 11
        }).setView(CALOOCAN_CENTER, 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Draw Caloocan boundary polygons (teal border + subtle fill)
        CALOOCAN_POLYGONS.forEach(function (poly) {
            L.polygon(poly, {
                color: '#0d6e8a',
                weight: 2,
                fillColor: '#0d6e8a',
                fillOpacity: 0.08,
                interactive: false
            }).addTo(map);
        });

        var latInput = document.getElementById('latitude');
        var lngInput = document.getElementById('longitude');
        var lastValidLatLng = L.latLng(CALOOCAN_CENTER[0], CALOOCAN_CENTER[1]);

        // ---- Map warning helper ----
        function showMapWarning(msg) {
            var box = document.getElementById('map-warning');
            box.textContent = msg;
            box.classList.remove('hidden');
            clearTimeout(showMapWarning._t);
            showMapWarning._t = setTimeout(function () {
                box.classList.add('hidden');
            }, 3000);
        }
        function clearMapWarning() {
            var box = document.getElementById('map-warning');
            box.textContent = '';
            box.classList.add('hidden');
        }

        // ---- Draggable preview marker ----
        var previewMarker = null;

        function setPreviewMarker(lat, lng) {
            if (previewMarker) {
                map.removeLayer(previewMarker);
            }
            previewMarker = L.marker([lat, lng], {
                draggable: true,
                icon: L.divIcon({
                    className: '',
                    html: '<div style="width:18px;height:18px;border-radius:50%;background:#0f766e;border:3px solid #fff;box-shadow:0 0 0 2px #0f766e;"></div>',
                    iconSize: [18, 18],
                    iconAnchor: [9, 9]
                })
            }).addTo(map);

            // Snap back if dragged outside Caloocan
            previewMarker.on('drag', function (e) {
                var pos = e.target.getLatLng();
                if (!isInCaloocan(pos.lat, pos.lng)) {
                    e.target.setLatLng(lastValidLatLng);
                }
            });

            previewMarker.on('dragend', function (e) {
                var pos = e.target.getLatLng();
                if (!isInCaloocan(pos.lat, pos.lng)) {
                    showMapWarning('You can only pin a location inside Caloocan City.');
                    return;
                }
                lastValidLatLng = pos;
                latInput.value = pos.lat.toFixed(6);
                lngInput.value = pos.lng.toFixed(6);
            });
        }

        // Click-to-pin (restricted to Caloocan polygons)
        map.on('click', function (e) {
            if (!isInCaloocan(e.latlng.lat, e.latlng.lng)) {
                showMapWarning('You can only pin a location inside Caloocan City.');
                return;
            }
            clearMapWarning();
            lastValidLatLng = e.latlng;
            latInput.value = e.latlng.lat.toFixed(6);
            lngInput.value = e.latlng.lng.toFixed(6);
            setPreviewMarker(e.latlng.lat, e.latlng.lng);
        });

        // ---- Field permission toggling ----
        function toggleField(key, show) {
            var el = document.getElementById('field-' + key);
            if (!el) return;
            if (show) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
                // Clear inputs so hidden data isn't submitted
                el.querySelectorAll('input, select, textarea').forEach(function(input) {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        input.checked = false;
                    } else {
                        input.value = '';
                    }
                });
            }
        }

        function applyFieldConfig() {
            var select = document.getElementById('category_id');
            var catId = select.value;
            var cfg = (catId && CATEGORY_FIELD_CONFIG[catId]) ? CATEGORY_FIELD_CONFIG[catId] : DEFAULT_FIELD_CONFIG;

            toggleField('plate_number',     !!cfg.plate_number);
            toggleField('coordinates',      !!cfg.coordinates);
            toggleField('gis_map',          !!cfg.gis_map);
            toggleField('maintenance',      !!cfg.maintenance);
            toggleField('acquisition_date', !!cfg.acquisition_date);
            toggleField('notes',            !!cfg.notes);

            // Map needs resize after its container becomes visible
            if (cfg.gis_map) {
                setTimeout(function() { map.invalidateSize(); }, 50);
            }
        }

        document.getElementById('category_id').addEventListener('change', applyFieldConfig);
        applyFieldConfig();
    });
</script>

<?php include $basePath . 'includes/footer.php'; ?>