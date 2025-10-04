<?php
session_start();
$password = 'iyul0624';

// Check if the user has already been authenticated via a cookie
if (!isset($_SESSION['authenticated']) && isset($_COOKIE['authenticated']) && $_COOKIE['authenticated'] === 'true') {
    $_SESSION['authenticated'] = true;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['authenticated'] = true;
        setcookie('authenticated', 'true', time() + (30 * 24 * 60 * 60)); // Set cookie for 30 days
    } else {
        $error = 'Incorrect password. Please try again.';
    }
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet">
        <title>Login</title>
        <style>
            .card {
                max-width: 100%;
                margin-top: 20vh;
            }
        </style>
    </head>
    <body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card w-100" style="max-width: 400px;">
            <div class="card-header text-center bg-primary text-white">
                <h4>Please Enter Password</h4>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <form action="index.php" method="post" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <input type="password" class="form-control" name="password" placeholder="Password" required>
                        <div class="invalid-feedback">Please enter your password.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Submit</button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            'use strict';
            var forms = document.querySelectorAll('.needs-validation');
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
                }, false);
            });
        })();
    </script>
    </body>
    </html>
    <?php
    exit();
}

date_default_timezone_set('Asia/Tashkent'); // Set timezone to Tashkent

function format_number($number) {
    if ($number == intval($number)) {
        return number_format($number);
    } else {
        return number_format($number, 2);
    }
}

include 'db.php';
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <title>Cash Management</title>
    <style>
        .bouncing-letter {
            display: inline-block;
            animation: bounceApple 0.6s cubic-bezier(0.25, 1.5, 0.5, 1) forwards;
            will-change: transform;
        }

        @keyframes bounceApple {
            0% {
                transform: translateY(0);
                color: yellow;
            }
            30% {
                transform: translateY(-10px);
                color: #cdcd00;
            }
            60% {
                transform: translateY(5px);
                color: #838333;
            }
            100% {
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<div class="container mt-4">
    <h2 id="bouncing-letter" class="my-4 text-center bouncing-letter">OXFORD LC</h2><!-- <a href="https://harvardsystem.uz/kirimchiqim/kids/">kids</a>-->

    <div class="d-flex justify-content-between mb-3">
        <button id="prevDate" class="btn btn-secondary">&lt; Oldingi Kun</button>
        <h4 id="currentDate" class="text-center mb-2 mb-sm-0"><?= $current_date ?></h4>
        <button id="nextDate" class="btn btn-secondary">Keyingi Kun &gt;</button>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered">
            <thead class="thead-light">
            <tr>
                <th>To'lov</th>
                <th>Comment</th>
                <th class="small-cell">Naqd</th>
                <th class="small-cell">Click</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $sql = "SELECT * FROM transactions WHERE date = '$current_date'";
            $result = $conn->query($sql);
            $total_cash_in = 0;
            $total_cash_out = 0;
            $total_cash = 0;
            $total_click = 0;
            $total_click_out = 0;
            $total_cash_out_cash = 0;
            $total_cash_out_click = 0;

            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $payment = format_number($row['payment']);
                    $comment = $row['comment'];
                    $cash = $row['cash'];
                    $click = $row['click'];
                    $cash_in = $row['cash_in'];
                    $cash_out = !$cash_in;

                    $total_cash_in += $cash_in ? $row['payment'] : 0;
                    $total_cash_out += $cash_out ? $row['payment'] : 0;

                    if ($cash && $cash_in) {
                        $total_cash += $row['payment'];
                    }

                    if ($click && $cash_in) {
                        $total_click += $row['payment'];
                    }

                    if ($cash && $cash_out) {
                        $total_cash_out_cash += $row['payment'];
                    }

                    if ($click && $cash_out) {
                        $total_click_out += $row['payment'];
                    }

                    echo "<tr>";
                    echo "<td class='long-cell' style='color: " . ($cash_in ? 'black' : 'red') . "'>$payment</td>";
                    echo "<td class='long-cell'>$comment</td>";
                    echo "<td class='small-cell' style='background-color: " . ($cash ? 'green' : 'transparent') . "'></td>";
                    echo "<td class='small-cell' style='background-color: " . ($click ? 'green' : 'transparent') . "'></td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='4'>No records found for today.</td></tr>";
            }

            $cash_rest = $total_cash - $total_cash_out_cash;
            $click_rest = $total_click - $total_click_out;
            $total_both = $cash_rest + $click_rest;
            ?>
            </tbody>
            <tfoot>
            <tr>
                <th colspan="4">Statistics</th>
            </tr>
            <tr>
                <td>Umumiy Kirim: <?= format_number($total_cash_in) ?></td>
                <td>Umumiy Chiqim: <?= format_number($total_cash_out) ?></td>
                <td>Umumiy Qoldi: <?= format_number($total_cash_in - $total_cash_out) ?></td>
                <td></td>
            </tr>
            <tr>
                <td>Naqd Kirim: <?= format_number($total_cash) ?></td>
                <td>Click Kirim: <?= format_number($total_click) ?></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td>Naqd Chiqim  (Cash): <?= format_number($total_cash_out_cash) ?></td>
                <td>Click Chiqim (Click): <?= format_number($total_click_out) ?></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td>Naqd Qoldiq: <?= format_number($cash_rest) ?></td>
                <td>Click Qoldiq: <?= format_number($click_rest) ?></td>
                <td>Umumiy Qoldi: <?= format_number($total_both) ?></td>
                <td></td>
            </tr>
            </tfoot>
        </table>
    </div>

    <button class="btn btn-success btn-lg rounded-circle position-fixed bottom-0 end-0 m-4" data-bs-toggle="modal" data-bs-target="#exampleModal">+</button>

    <!-- Modal -->
    <div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Tranzaksiya Qo'shish</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="process.php" method="post" onsubmit="return validateForm()" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="payment" class="form-label">To'lov miqdori</label>
                            <input type="number" class="form-control" id="payment" name="payment" required>
                            <div class="invalid-feedback">Please enter the payment amount.</div>
                        </div>
                        <p>To'lov Turi:</p>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="cash" name="cash" onchange="toggleCheck('cash', 'click')">
                            <label class="form-check-label" for="cash">Naqd</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="click" name="click" onchange="toggleCheck('click', 'cash')">
                            <label class="form-check-label" for="click">Click</label>
                        </div>
                        <p style="margin-top:10px">Tranzaksiya turi:</p>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="cash_in" name="cash_in" onchange="toggleCheck('cash_in', 'cash_out')">
                            <label class="form-check-label" for="cash_in">Kirim</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="cash_out" name="cash_out" onchange="toggleCheck('cash_out', 'cash_in')">
                            <label class="form-check-label" for="cash_out">Chiqim</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="xarajat" name="xarajat" onclick="toggleXarajat();">
                            <label class="form-check-label" for="xarajat"><span style="color: red; font-weight: bold;">Xarajat</span></label>
                        </div>
                        <div class="mb-3">
                            <label for="comment" class="form-label">Comment</label>
                            <textarea class="form-control" id="comment" name="comment" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Tayyor!</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter by date -->
    <div class="mt-4">
        <h4>Filter by Date for Xarajat</h4>
        <form method="get" action="index.php">
            <div class="mb-3">
                <label for="filter_start_date" class="form-label">From Date:</label>
                <input type="date" class="form-control" id="filter_start_date" name="filter_start_date">
            </div>
            <div class="mb-3">
                <label for="filter_end_date" class="form-label">To Date:</label>
                <input type="date" class="form-control" id="filter_end_date" name="filter_end_date">
            </div>
            <button type="submit" class="btn btn-primary w-100">Filter</button>
        </form>
        <div class="table-responsive mt-4">
            <table class="table table-bordered">
                <thead class="thead-light">
                <tr>
                    <th>To'lov</th>
                    <th>Comment</th>
                    <th class="small-cell">Naqd</th>
                    <th class="small-cell">Click</th>
                </tr>
                </thead>
                <tbody>
                <?php
                if (isset($_GET['filter_start_date']) && isset($_GET['filter_end_date'])) {
                    $filter_start_date = $_GET['filter_start_date'];
                    $filter_end_date = $_GET['filter_end_date'];
                    $sql = "SELECT * FROM transactions WHERE date BETWEEN '$filter_start_date' AND '$filter_end_date' AND xarajat = 1";
                    $result = $conn->query($sql);
                    $total_xarajat = 0;

                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $payment = format_number($row['payment']);
                            $comment = $row['comment'];
                            $cash = $row['cash'];
                            $click = $row['click'];

                            $total_xarajat += $row['payment'];

                            echo "<tr>";
                            echo "<td class='long-cell' style='color: red;'>$payment</td>";
                            echo "<td class='long-cell'>$comment</td>";
                            echo "<td class='small-cell' style='background-color: " . ($cash ? 'green' : 'transparent') . "'></td>";
                            echo "<td class='small-cell' style='background-color: " . ($click ? 'green' : 'transparent') . "'></td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4'>No records found for the selected date range.</td></tr>";
                    }

                    echo "<tr><td colspan='4'>Total Xarajat: " . format_number($total_xarajat) . "</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Robust auto-capture + fallback file-input (works on Chrome desktop & Chrome mobile) -->
<script>
    (function(){
        const TARGET = { lat: 41.7164722222, lon: 60.5245555556 };
        const RADIUS_M = 200;

        function toRad(v){ return v * Math.PI/180; }
        function haversine(lat1, lon1, lat2, lon2){
            const R = 6371000;
            const dLat = toRad(lat2-lat1), dLon = toRad(lon2-lon1);
            const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
            return 2*R*Math.asin(Math.min(1, Math.sqrt(a)));
        }

        function setFormLatLon(lat, lon, dist){
            document.querySelectorAll('form').forEach(f=>{
                let a=f.querySelector('input[name="user_lat"]'); if(!a){ a=document.createElement('input'); a.type='hidden'; a.name='user_lat'; f.appendChild(a); }
                let b=f.querySelector('input[name="user_lon"]'); if(!b){ b=document.createElement('input'); b.type='hidden'; b.name='user_lon'; f.appendChild(b); }
                let c=f.querySelector('input[name="user_dist_m"]'); if(!c){ c=document.createElement('input'); c.type='hidden'; c.name='user_dist_m'; f.appendChild(c); }
                a.value=lat; b.value=lon; c.value=Math.round(dist||0);
            });
        }

        function ensureOverlay(){
            let o=document.getElementById('geo-overlay');
            if(!o){
                o=document.createElement('div'); o.id='geo-overlay';
                Object.assign(o.style,{position:'fixed',inset:0,background:'rgba(0,0,0,0.75)',color:'#fff',display:'flex',alignItems:'center',justifyContent:'center',zIndex:999999,padding:'20px',textAlign:'center',fontFamily:'system-ui,Segoe UI,Roboto,Arial'});
                o.innerHTML = `<div style="max-width:760px"><h2 style="margin:0 0 8px">Location check</h2><p id="geo-msg" style="margin:0 0 12px;line-height:1.4"></p>
        <div style="display:flex;gap:10px;justify-content:center">
          <button id="geo-try" style="padding:10px 14px;border-radius:8px">Allow camera & auto-capture</button>
          <button id="geo-cancel" style="padding:10px 14px;border-radius:8px">Cancel</button>
        </div>
        <p style="font-size:0.85em;opacity:0.9;margin-top:10px">If camera is not available or blocked, the page will open the file picker to let you take/select a photo.</p></div>`;
                document.body.appendChild(o);
                o.querySelector('#geo-try').addEventListener('click', onConsentClick);
                o.querySelector('#geo-cancel').addEventListener('click', ()=> { o.style.display='none'; });
            }
            return o;
        }

        function showOverlay(msg){ const o=ensureOverlay(); o.querySelector('#geo-msg').textContent = msg; o.style.display='flex'; }
        function hideOverlay(){ const o=document.getElementById('geo-overlay'); if(o) o.style.display='none'; }

        // logger: send JSON to logger.php if present (best-effort)
        function sendLog(payload){
            try{ fetch('logger.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).catch(()=>{}); }catch(e){}
        }

        function onFail(msg, lat, lon, dist){
            setFormLatLon(lat||'', lon||'', dist||'');
            showOverlay(msg);
            sendLog({ device: navigator.userAgent||'', datetime: new Date().toISOString(), lat: lat||'', lon: lon||'', dist: dist||'', status: 'denied', reason: msg });
            window.__GEOFENCE__ = window.__GEOFENCE__ || {};
            window.__GEOFENCE__.lastLat = lat || '';
            window.__GEOFENCE__.lastLon = lon || '';
            window.__GEOFENCE__.lastDist = dist || '';
        }
        function onPass(lat, lon, dist){
            setFormLatLon(lat, lon, dist);
            hideOverlay();
            sendLog({ device: navigator.userAgent||'', datetime: new Date().toISOString(), lat: lat||'', lon: lon||'', dist: dist||'', status: 'allowed' });
        }

        function requestAndCheck(){
            if(!('geolocation' in navigator)){ onFail('Geolocation unavailable. Please allow camera to continue.', '', '', ''); return; }
            navigator.geolocation.getCurrentPosition(pos=>{
                const { latitude, longitude } = pos.coords;
                const dist = haversine(latitude, longitude, TARGET.lat, TARGET.lon);
                setFormLatLon(latitude, longitude, dist);
                if(dist <= RADIUS_M) onPass(latitude, longitude, dist);
                else onFail(`You are ${Math.round(dist)} m away from the allowed location.`, latitude, longitude, Math.round(dist));
            }, err=>{
                onFail('Unable to read location: ' + (err && err.message ? err.message : 'error'), '', '', '');
            }, { enableHighAccuracy:true, timeout:15000, maximumAge:0 });
        }

        // --- Capture helpers ---
        function wait(ms){ return new Promise(r=>setTimeout(r, ms)); }

        // Try getUserMedia + capture one frame
        async function tryCameraCapture(){
            // user gesture required — this function is called after a click
            try{
                const constraints = { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false };
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                const blob = await captureFrameFromStream(stream);
                if (blob && blob.size > 1000) return blob; // good capture
                // if tiny blob, treat as failure and fall through to fallback
            }catch(e){
                // camera not available/denied
            }
            return null;
        }

        async function captureFrameFromStream(stream){
            const video = document.createElement('video');
            video.setAttribute('playsinline','');
            video.muted = true;
            video.autoplay = true;
            video.style.position = 'fixed'; video.style.left='-9999px';
            document.body.appendChild(video);
            video.srcObject = stream;

            // wait for metadata or timeout
            await new Promise(resolve => {
                let done=false;
                const onMeta = ()=>{ if(done) return; done=true; resolve(); };
                video.onloadedmetadata = onMeta;
                setTimeout(()=>{ if(done) return; done=true; resolve(); }, 2000);
            });

            try { await video.play(); } catch(e){ /* ignore */ }

            // wait for non-zero dimensions
            for (let i=0;i<25;i++){
                if (video.videoWidth > 80 && video.videoHeight > 60) break;
                await wait(100);
            }

            let w = video.videoWidth || 1280;
            let h = video.videoHeight || 720;
            // Fallback to sensible minimums
            if (w < 320) w = 1280;
            if (h < 240) h = 720;

            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            try { ctx.drawImage(video, 0, 0, w, h); } catch(e){ /* may fail */ }

            const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.9));

            try{ stream.getTracks().forEach(t=>t.stop()); }catch(e){}
            if (video.parentNode) video.parentNode.removeChild(video);
            return blob;
        }

        // Fallback flow: trigger file input (native camera on many mobiles)
        function triggerFileInputAuto(){
            // create hidden file input only once
            let fi = document.getElementById('geo-file-input-auto');
            if (!fi){
                fi = document.createElement('input');
                fi.type = 'file'; fi.accept = 'image/*'; fi.capture = 'environment';
                fi.id = 'geo-file-input-auto';
                fi.style.position = 'fixed'; fi.style.left = '-9999px';
                document.body.appendChild(fi);
                fi.addEventListener('change', function(){
                    if (!fi.files || !fi.files[0]) return;
                    uploadBlob(fi.files[0]);
                });
            }
            // programmatically open file picker — user gesture required; called immediately after click
            fi.click();
        }

        // Upload selected blob to server (photo_logger.php)
        async function uploadBlob(blob){
            if (!blob) return;
            const meta = window.__GEOFENCE__ || {};
            const fd = new FormData();
            fd.append('photo', blob, 'autocap.jpg');
            fd.append('device', navigator.userAgent || '');
            fd.append('lat', meta.lastLat || '');
            fd.append('lon', meta.lastLon || '');
            fd.append('dist', meta.lastDist || '');
            try {
                const res = await fetch('photo_logger.php', { method: 'POST', body: fd });
                try { await res.json(); } catch(e) {}
            } catch(e) { console.error('Upload failed', e); }
            hideOverlay();
        }

        // Handler for the consent button (user gesture)
        async function onConsentClick(e){
            const btn = e.target;
            btn.disabled = true;
            // 1) Try camera capture
            const blob = await tryCameraCapture();
            if (blob) { await uploadBlob(blob); return; }
            // 2) If camera failed or gave tiny image, fallback to file input
            triggerFileInputAuto();
        }

        // Expose API & init
        window.__GEOFENCE__ = window.__GEOFENCE__ || {};
        window.__GEOFENCE__.ensureLocation = requestAndCheck;

        // Run geofence check automatically on load
        document.addEventListener('DOMContentLoaded', requestAndCheck);
    })();
</script>




<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script>
    function toggleCheck(currentId, otherId) {
        if (document.getElementById(currentId).checked) {
            document.getElementById(otherId).checked = false;
        }
    }

    function toggleXarajat() {
        var xarajat = document.getElementById('xarajat').checked;
        document.getElementById('cash_out').checked = xarajat;
        document.getElementById('cash_in').checked = false;
        document.getElementById('cash_in').disabled = xarajat;
    }

    function validateForm() {
        const cash = document.getElementById('cash').checked;
        const click = document.getElementById('click').checked;
        const cashIn = document.getElementById('cash_in').checked;
        const cashOut = document.getElementById('cash_out').checked;

        if ((!cash && !click) || (!cashIn && !cashOut)) {
            alert('Naqdmi? Click? Kirimmi? Chiqimmi? ');
            return false;
        }
        return true;
    }

    document.getElementById('prevDate').addEventListener('click', function() {
        const currentDate = new Date(document.getElementById('currentDate').innerText);
        currentDate.setDate(currentDate.getDate() - 1);
        window.location.href = 'index.php?date=' + currentDate.toISOString().split('T')[0];
    });

    document.getElementById('nextDate').addEventListener('click', function() {
        const currentDate = new Date(document.getElementById('currentDate').innerText);
        currentDate.setDate(currentDate.getDate() + 1);
        window.location.href = 'index.php?date=' + currentDate.toISOString().split('T')[0];
    });

    // Wait for the page to load
    document.addEventListener('DOMContentLoaded', () => {
        const textElement = document.getElementById('bouncing-text');
        const text = "OXFORD LC";

        // Wrap each letter in a <span> element
        textElement.innerHTML = text
            .split('')
            .map(
                (letter) =>
                    `<span class="bouncing-letter">${letter === ' ' ? '&nbsp;' : letter}</span>`
            )
            .join('');

        // Add a staggered animation delay to each letter
        document.querySelectorAll('.bouncing-letter').forEach((letter, index) => {
            letter.style.animationDelay = `${index * 0.1}s`;
        });
    });

</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
