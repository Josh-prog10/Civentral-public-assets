<?php
declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/WaterDrainageService.php';

use App\Service\WaterDrainageService;

/**
 * Approximate boundary polygons for Caloocan City (South + North).
 * Each polygon is an array of [lat, lng] pairs.
 */
function caloocanPolygons(): array
{
    return [
        // South Caloocan
        [
            [14.6140, 120.9550], [14.6130, 120.9720], [14.6180, 120.9950],
            [14.6350, 121.0030], [14.6550, 121.0050], [14.6700, 121.0000],
            [14.6880, 120.9870], [14.6900, 120.9700], [14.6800, 120.9550],
            [14.6600, 120.9500], [14.6400, 120.9520],
        ],
        // North Caloocan
        [
            [14.7050, 120.9750], [14.6980, 121.0000], [14.7100, 121.0200],
            [14.7300, 121.0350], [14.7500, 121.0600], [14.7700, 121.0750],
            [14.7850, 121.0700], [14.7900, 121.0500], [14.7850, 121.0200],
            [14.7750, 120.9900], [14.7600, 120.9700], [14.7400, 120.9650],
            [14.7200, 120.9680],
        ],
    ];
}

function isWithinCaloocan(float $lat, float $lng): bool
{
    foreach (caloocanPolygons() as $poly) {
        $inside = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $poly[$i][1]; $yi = $poly[$i][0];
            $xj = $poly[$j][1]; $yj = $poly[$j][0];
            $intersect = (($yi > $lat) !== ($yj > $lat)) &&
                ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        if ($inside) {
            return true;
        }
    }
    return false;
}

$wdService = new WaterDrainageService($pdo);

if (!$isSuperAdmin && !$hasResourceAccess(['water_drainage', 'water drainage', 'water supply', 'drainage'])) {
    header('Location: ' . $basePath . 'pages/dashboard.php?error=access_denied');
    exit;
}

$categories = $wdService->getCategories();
$message = $error = '';
if (empty($_SESSION['water_drainage_csrf_token'])) {
    $_SESSION['water_drainage_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['water_drainage_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, $_POST['csrf_token'])) {
        $error = 'Invalid request.';
    } else {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $locationText = trim($_POST['location_text'] ?? '');
        $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
        $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
        $userId = $headerUser['id'] ?? ($_SESSION['user_id'] ?? null);

        // --- NEW: Strict Name Validation ---
        if ($categoryId < 1 || $title === '' || $locationText === '') {
            $error = 'Category, Name, and Location are required.';
        } elseif (strlen($title) < 2) {
            $error = 'Name must be at least 2 characters long.';
        } elseif (!preg_match('/^[A-Za-z\s\.\-\']+$/', $title)) {
            $error = 'Name can only contain letters, spaces, hyphens, and apostrophes.';
        } elseif (!preg_match('/[aeiouAEIOU]/', $title)) {
            $error = 'Name must contain at least one vowel.';
        } elseif (preg_match('/(.)\1{2,}/', $title)) {
            $error = 'Name contains too many repeated characters.';
        } elseif ($lat !== null && $lng !== null && !isWithinCaloocan($lat, $lng)) {
            // Server-side guard: reject pins outside Caloocan City
            $error = 'Pin location must be inside Caloocan City.';
        } else {
            $uploadDir = __DIR__ . '/../../public/uploads/water/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $validFiles = [];
            $fileErrors = [];

            if (!isset($_FILES['attachments']) || empty($_FILES['attachments']['name'][0])) {
                $error = 'Proof / Evidence is required. Please upload at least one file.';
            } else {
                $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $maxSize = 10 * 1024 * 1024; // 10 MB

                $fileCount = count($_FILES['attachments']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    $file = [
                        'name'     => $_FILES['attachments']['name'][$i],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'error'    => $_FILES['attachments']['error'][$i],
                        'size'     => $_FILES['attachments']['size'][$i],
                    ];

                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $fileErrors[] = "File '{$file['name']}' upload error (code {$file['error']}).";
                        continue;
                    }

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!in_array($mime, $allowedMimes, true)) {
                        $fileErrors[] = "File '{$file['name']}' has an unsupported type ({$mime}).";
                        continue;
                    }

                    if ($file['size'] > $maxSize) {
                        $fileErrors[] = "File '{$file['name']}' exceeds the 10 MB limit.";
                        continue;
                    }

                    $validFiles[] = $file;
                }

                if (empty($validFiles)) {
                    if (empty($fileErrors)) {
                        $error = 'Proof / Evidence is required. Please upload at least one valid file.';
                    } else {
                        $error = 'Attachment validation failed: ' . implode(' ', $fileErrors);
                    }
                }
            }

            // If no error, proceed
            if (empty($error)) {
                try {
                    $success = $wdService->addRequest($categoryId, $userId, $title, $description, $locationText, $lat, $lng);
                    if ($success) {
                        $newRequestId = (int)$pdo->lastInsertId();

                        foreach ($validFiles as $file) {
                            $wdService->addAttachment($newRequestId, $file, $uploadDir);
                        }

                        $message = 'Request submitted successfully!';
                    } else {
                        $error = 'Failed to submit request.';
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Submit Water/Drainage Request';
include $basePath . 'includes/header.php';
include $basePath . 'includes/sidebar.php';
?>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    #map { height: 300px; border-radius: 0.5rem; border: 1px solid #cbd5e1; }
    .dark #map { border-color: #334155; }
</style>

<div class="flex-1 p-6 lg:p-8">
    <div class="max-w-3xl mx-auto">
        <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight mb-6">
            <i class="fa-solid fa-pen-to-square text-brand-medium mr-3"></i>Submit a Request
        </h1>

        <?php if ($message): ?>
            <div class="mb-4 p-3 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 rounded-lg text-sm font-bold"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 rounded-lg text-sm font-bold"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Category</label>
                    <select name="category_id" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                        <option value="">Select category</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <!-- Changed label from Title to Name -->
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Name</label>
                    <!-- Added pattern and title for frontend validation -->
                    <input type="text" name="title" required 
                           pattern="[A-Za-z\s\.\-']+" 
                           title="Please enter a valid name (letters, spaces, hyphens, apostrophes only)."
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Description (optional)</label>
                    <textarea name="description" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Location Address</label>
                    <input type="text" name="location_text" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-white text-sm focus:ring-2 focus:ring-brand-medium focus:outline-none" placeholder="e.g. Barangay Hall, Street name">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">
                        Pin Location on Map <span class="text-slate-400 dark:text-slate-500 font-normal">(inside Caloocan City only)</span>
                    </label>
                    <div id="map"></div>
                    <input type="hidden" name="latitude" id="latitude" value="">
                    <input type="hidden" name="longitude" id="longitude" value="">
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Lat: <span id="latDisplay">-</span> | Lng: <span id="lngDisplay">-</span></p>
                    <p id="mapWarning" class="hidden mt-2 text-xs font-bold text-rose-600 dark:text-rose-400"></p>
                </div>

                <!-- Proof / Evidence – required -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-1">Proof / Evidence <span class="text-rose-500">*</span></label>
                    <input type="file" name="attachments[]" multiple accept="image/*,application/pdf,.doc,.docx"
                            required
                            class="block w-full text-sm text-slate-500 dark:text-slate-400
                                file:mr-4 file:py-2 file:px-4
                                file:rounded-full file:border-0
                                file:text-sm file:font-semibold
                                file:bg-brand-medium file:text-white
                                hover:file:bg-brand-dark
                                cursor-pointer">
                    <p class="text-xs text-slate-400 mt-1">Accepted: JPG, PNG, GIF, PDF, DOC, DOCX. Max 10MB each. At least one file required.</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="px-6 py-2 bg-brand-dark hover:bg-[#0e4f62] text-white font-bold text-sm rounded-lg transition shadow-sm">
                        <i class="fa-solid fa-check mr-1"></i> Submit
                    </button>
                    <a href="<?= $basePath ?>pages/water-drainage/wdb.php" class="px-6 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-sm rounded-lg hover:bg-slate-300 dark:hover:bg-slate-600 transition">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Approximate boundary polygons for Caloocan City (South + North).
        // Format: [lat, lng]
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

        // Start at Monumento, Caloocan
        var initialLat = 14.6572, initialLng = 120.9837;

        var map = L.map('map', {
            maxBounds: [[14.55, 120.90], [14.85, 121.13]],
            maxBoundsViscosity: 0.8,
            minZoom: 11
        }).setView([initialLat, initialLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        // Draw allowed area overlays
        CALOOCAN_POLYGONS.forEach(function (poly) {
            L.polygon(poly, {
                color: '#0d6e8a',
                weight: 2,
                fillColor: '#0d6e8a',
                fillOpacity: 0.08,
                interactive: false
            }).addTo(map);
        });

        var lastValidLatLng = L.latLng(initialLat, initialLng);
        var marker = L.marker(lastValidLatLng, { draggable: true }).addTo(map);

        var warnEl = document.getElementById('mapWarning');

        function showWarning(msg) {
            if (!warnEl) return;
            warnEl.textContent = msg;
            warnEl.classList.remove('hidden');
            clearTimeout(showWarning._t);
            showWarning._t = setTimeout(function () {
                warnEl.classList.add('hidden');
            }, 3000);
        }

        function updateCoords(lat, lng) {
            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;
            document.getElementById('latDisplay').textContent = lat.toFixed(6);
            document.getElementById('lngDisplay').textContent = lng.toFixed(6);
        }

        updateCoords(initialLat, initialLng);

        marker.on('dragend', function () {
            var pos = marker.getLatLng();
            if (!isInCaloocan(pos.lat, pos.lng)) {
                showWarning('You can only pin a location inside Caloocan City.');
                marker.setLatLng(lastValidLatLng);
                return;
            }
            lastValidLatLng = pos;
            updateCoords(pos.lat, pos.lng);
        });

        map.on('click', function (e) {
            var latlng = e.latlng;
            if (!isInCaloocan(latlng.lat, latlng.lng)) {
                showWarning('You can only pin a location inside Caloocan City.');
                return;
            }
            lastValidLatLng = latlng;
            marker.setLatLng(latlng);
            updateCoords(latlng.lat, latlng.lng);
        });
    });
</script>

<?php include $basePath . 'includes/footer.php'; ?>