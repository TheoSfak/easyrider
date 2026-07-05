(function () {
    var DURATION_MS = 25000;

    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('rideReplayModal');
        var data = window.easyRideReplayData;
        if (!modalEl || !data || !Array.isArray(data.points) || data.points.length < 2) {
            return;
        }

        var map = null;
        var marker = null;
        var eventMarkers = [];
        var rafId = null;
        var playing = false;
        var elapsedMs = 0;
        var lastFrameTime = null;

        var cumulative = [0];
        for (var i = 1; i < data.points.length; i++) {
            cumulative.push(cumulative[i - 1] + haversineMeters(data.points[i - 1], data.points[i]));
        }
        var totalDistance = cumulative[cumulative.length - 1];

        var severityColor = {
            danger: '#dc3545',
            warning: '#f59e0b',
            secondary: '#64748b',
            info: '#0d6efd'
        };

        function haversineMeters(a, b) {
            var R = 6371000;
            var lat1 = a[0] * Math.PI / 180;
            var lat2 = b[0] * Math.PI / 180;
            var dLat = (b[0] - a[0]) * Math.PI / 180;
            var dLng = (b[1] - a[1]) * Math.PI / 180;
            var sinA = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return R * 2 * Math.atan2(Math.sqrt(sinA), Math.sqrt(1 - sinA));
        }

        function positionAtFraction(fraction) {
            var targetDistance = fraction * totalDistance;
            for (var i = 0; i < cumulative.length - 1; i++) {
                if (targetDistance <= cumulative[i + 1] || i === cumulative.length - 2) {
                    var segStart = cumulative[i];
                    var segEnd = cumulative[i + 1];
                    var segFraction = segEnd > segStart ? (targetDistance - segStart) / (segEnd - segStart) : 0;
                    segFraction = Math.max(0, Math.min(1, segFraction));
                    var a = data.points[i];
                    var b = data.points[i + 1];
                    return [
                        a[0] + (b[0] - a[0]) * segFraction,
                        a[1] + (b[1] - a[1]) * segFraction
                    ];
                }
            }
            return data.points[data.points.length - 1];
        }

        function formatKm(meters) {
            return meters >= 1000
                ? (meters / 1000).toFixed(1).replace('.', ',') + ' km'
                : Math.round(meters) + ' m';
        }

        function formatMinutes(minutes) {
            var rounded = Math.round(minutes);
            var hours = Math.floor(rounded / 60);
            var mins = rounded % 60;
            return hours > 0 ? (hours + 'ω ' + mins + 'λ') : (mins + ' λεπτά');
        }

        function updateFrame(fraction) {
            var pos = positionAtFraction(fraction);
            marker.setLatLng(pos);

            document.getElementById('rideReplayKm').textContent = formatKm(fraction * data.distanceMeters);
            document.getElementById('rideReplayTime').textContent = formatMinutes(fraction * data.totalMinutes);
            document.getElementById('rideReplayScrubber').value = fraction;

            eventMarkers.forEach(function (entry) {
                var shouldHighlight = fraction >= entry.routeFraction;
                if (shouldHighlight !== entry.highlighted) {
                    entry.highlighted = shouldHighlight;
                    entry.marker.setStyle({
                        radius: shouldHighlight ? 7 : 5,
                        fillOpacity: shouldHighlight ? 1 : 0.4,
                        opacity: shouldHighlight ? 1 : 0.4
                    });
                }
            });
        }

        function step(timestamp) {
            if (lastFrameTime === null) {
                lastFrameTime = timestamp;
            }
            elapsedMs += timestamp - lastFrameTime;
            lastFrameTime = timestamp;

            var fraction = Math.min(1, elapsedMs / DURATION_MS);
            updateFrame(fraction);

            if (fraction >= 1) {
                setPlaying(false);
                return;
            }
            if (playing) {
                rafId = requestAnimationFrame(step);
            }
        }

        function setPlaying(next) {
            playing = next;
            var icon = document.querySelector('#rideReplayPlayPause i');
            icon.className = playing ? 'bi bi-pause-fill' : 'bi bi-play-fill';
            if (playing) {
                lastFrameTime = null;
                rafId = requestAnimationFrame(step);
            } else if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
        }

        function initMap() {
            map = L.map('rideReplayMap', { zoomControl: true, scrollWheelZoom: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19
            }).addTo(map);

            L.polyline(data.points, { color: '#0d6efd', weight: 4, opacity: 0.4 }).addTo(map);
            map.fitBounds(L.latLngBounds(data.points), { padding: [20, 20] });

            (data.events || []).forEach(function (event) {
                var color = severityColor[event.severity] || severityColor.info;
                var circleMarker = L.circleMarker([event.lat, event.lng], {
                    radius: 5,
                    color: color,
                    fillColor: color,
                    fillOpacity: 0.4,
                    opacity: 0.4,
                    weight: 2
                }).addTo(map).bindPopup(event.title);
                eventMarkers.push({ marker: circleMarker, routeFraction: event.routeFraction, highlighted: false });
            });

            marker = L.circleMarker(data.points[0], {
                radius: 8,
                color: '#198754',
                fillColor: '#198754',
                fillOpacity: 1,
                weight: 2
            }).addTo(map);

            setTimeout(function () { map.invalidateSize(); }, 150);
        }

        function reset() {
            elapsedMs = 0;
            lastFrameTime = null;
            eventMarkers.forEach(function (entry) {
                entry.highlighted = false;
                entry.marker.setStyle({ radius: 5, fillOpacity: 0.4, opacity: 0.4 });
            });
            updateFrame(0);
        }

        modalEl.addEventListener('shown.bs.modal', function () {
            if (!map) {
                initMap();
                reset();
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            setPlaying(false);
            if (map) {
                map.remove();
                map = null;
                marker = null;
                eventMarkers = [];
            }
        });

        document.getElementById('rideReplayPlayPause').addEventListener('click', function () {
            if (elapsedMs / DURATION_MS >= 1) {
                reset();
            }
            setPlaying(!playing);
        });

        document.getElementById('rideReplayRestart').addEventListener('click', function () {
            setPlaying(false);
            reset();
        });

        document.getElementById('rideReplayScrubber').addEventListener('input', function (e) {
            setPlaying(false);
            elapsedMs = parseFloat(e.target.value) * DURATION_MS;
            updateFrame(parseFloat(e.target.value));
        });
    });
})();
