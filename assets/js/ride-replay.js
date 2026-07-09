function initRideReplayController(options) {
    var modalEl = options.modalEl || null;
    var elIds = options.elIds;
    var getData = options.getData;
    var standalone = !!options.standalone;
    var autoplay = !!options.autoplay;
    if (!standalone && !modalEl) return;

    var DURATION_MS = 25000;
    var map = null;
    var markers = [];
    var polylines = [];
    var referencePolyline = null;
    var eventMarkers = [];
    var timelineEntries = [];
    var rafId = null;
    var playing = false;
    var elapsedMs = 0;
    var lastFrameTime = null;
    var data = null;
    var startTs = 0;
    var endTs = 0;
    var durationSeconds = 0;

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

    function normalizeData(raw) {
        if (!raw) return null;
        if (Array.isArray(raw.tracks) && raw.tracks.length) {
            return raw;
        }

        if (Array.isArray(raw.points) && raw.points.length >= 2) {
            var now = Math.floor(Date.now() / 1000);
            return {
                mode: 'planned',
                tracks: [{
                    id: 'route',
                    label: 'Προγραμματισμένη διαδρομή',
                    color: '#198754',
                    points: raw.points.map(function (p, index) {
                        return { lat: p[0], lng: p[1], ts: now + index };
                    }),
                    distanceMeters: raw.distanceMeters || 0
                }],
                events: raw.events || [],
                referenceRoute: [],
                startTime: null,
                endTime: null,
                durationSeconds: Math.max(1, raw.points.length - 1),
                distanceMeters: raw.distanceMeters || 0,
                totalMinutes: raw.totalMinutes || 0
            };
        }

        return null;
    }

    function pointLatLng(point) {
        return [parseFloat(point.lat), parseFloat(point.lng)];
    }

    function positionAtTimestamp(track, ts) {
        var points = track.points || [];
        if (!points.length) return null;
        if (ts <= points[0].ts || points.length === 1) return pointLatLng(points[0]);
        if (ts >= points[points.length - 1].ts) return pointLatLng(points[points.length - 1]);

        for (var i = 0; i < points.length - 1; i++) {
            var a = points[i];
            var b = points[i + 1];
            if (ts >= a.ts && ts <= b.ts) {
                var span = Math.max(1, b.ts - a.ts);
                var f = Math.max(0, Math.min(1, (ts - a.ts) / span));
                return [
                    parseFloat(a.lat) + (parseFloat(b.lat) - parseFloat(a.lat)) * f,
                    parseFloat(a.lng) + (parseFloat(b.lng) - parseFloat(a.lng)) * f
                ];
            }
        }
        return pointLatLng(points[points.length - 1]);
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

    function formatClockFromTs(ts) {
        var date = new Date(ts * 1000);
        return date.toLocaleTimeString('el-GR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return {'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'}[ch];
        });
    }

    function updateFrame(fraction) {
        var currentTs = startTs + (durationSeconds * fraction);
        markers.forEach(function (entry) {
            var pos = positionAtTimestamp(entry.track, currentTs);
            if (pos) {
                entry.marker.setLatLng(pos);
            }
        });

        document.getElementById(elIds.km).textContent = formatKm(fraction * data.distanceMeters);
        document.getElementById(elIds.time).textContent = data.startTime ? formatClockFromTs(currentTs) : formatMinutes(fraction * data.totalMinutes);
        document.getElementById(elIds.scrubber).value = fraction;

        eventMarkers.forEach(function (entry) {
            var shouldHighlight = entry.ts ? currentTs >= entry.ts : fraction >= (entry.routeFraction || 0);
            if (shouldHighlight !== entry.highlighted) {
                entry.highlighted = shouldHighlight;
                entry.marker.setStyle({
                    radius: shouldHighlight ? 7 : 5,
                    fillOpacity: shouldHighlight ? 1 : 0.4,
                    opacity: shouldHighlight ? 1 : 0.4
                });
            }
        });

        timelineEntries.forEach(function (entry) {
            var active = entry.ts ? currentTs >= entry.ts : false;
            entry.el.classList.toggle('text-dark', active);
            entry.el.classList.toggle('fw-semibold', active);
            entry.el.classList.toggle('text-muted', !active);
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
        var icon = document.querySelector('#' + elIds.playPause + ' i');
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
        map = L.map(elIds.map, { zoomControl: true, scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19
        }).addTo(map);

        var boundsPoints = [];

        if (Array.isArray(data.referenceRoute) && data.referenceRoute.length >= 2) {
            referencePolyline = L.polyline(data.referenceRoute, {
                color: '#94a3b8',
                weight: 3,
                opacity: 0.35,
                dashArray: '6,8'
            }).addTo(map);
            boundsPoints = boundsPoints.concat(data.referenceRoute);
        }

        (data.tracks || []).forEach(function (track) {
            var latLngs = (track.points || []).map(pointLatLng);
            boundsPoints = boundsPoints.concat(latLngs);
            if (latLngs.length >= 2) {
                polylines.push(L.polyline(latLngs, {
                    color: track.color || '#2563eb',
                    weight: 4,
                    opacity: 0.65
                }).addTo(map));
            }

            if (latLngs.length) {
                var marker = L.circleMarker(latLngs[0], {
                    radius: track.isLead ? 9 : 7,
                    color: track.color || '#2563eb',
                    fillColor: track.color || '#2563eb',
                    fillOpacity: 1,
                    weight: track.isLead ? 3 : 2
                }).addTo(map).bindPopup(esc(track.label));
                markers.push({ marker: marker, track: track });
            }
        });

        if (boundsPoints.length) {
            map.fitBounds(L.latLngBounds(boundsPoints), { padding: [20, 20] });
        }

        (data.events || []).forEach(function (event) {
            var color = severityColor[event.severity] || severityColor.info;
            var circleMarker = L.circleMarker([event.lat, event.lng], {
                radius: 5,
                color: color,
                fillColor: color,
                fillOpacity: 0.4,
                opacity: 0.4,
                weight: 2
            }).addTo(map).bindPopup(esc(event.label || event.title));
            eventMarkers.push({ marker: circleMarker, routeFraction: event.routeFraction, ts: event.ts || null, highlighted: false });
        });

        setTimeout(function () { map.invalidateSize(); }, 150);
    }

    function renderLegend() {
        if (!elIds.legend) return;
        var el = document.getElementById(elIds.legend);
        if (!el) return;
        el.innerHTML = (data.tracks || []).map(function (track) {
            return '<span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1">'
                + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + esc(track.color) + '"></span>'
                + esc(track.label)
                + '</span>';
        }).join('');
    }

    function renderTimeline() {
        timelineEntries = [];
        if (!elIds.timeline) return;
        var el = document.getElementById(elIds.timeline);
        if (!el) return;
        var events = (data.events || []).slice().sort(function (a, b) { return (a.ts || 0) - (b.ts || 0); });
        if (!events.length) {
            el.innerHTML = '<div class="text-muted">Δεν καταγράφηκαν συμβάντα διαδρομής.</div>';
            return;
        }
        el.innerHTML = '<div class="d-flex flex-column gap-1">'
            + events.map(function (event, index) {
                return '<div class="text-muted" data-replay-event-index="' + index + '">'
                    + '<span class="badge bg-light text-dark border me-1">' + esc(event.time || '') + '</span>'
                    + esc(event.label || event.title)
                    + '</div>';
            }).join('')
            + '</div>';
        events.forEach(function (event, index) {
            var row = el.querySelector('[data-replay-event-index="' + index + '"]');
            if (row) timelineEntries.push({ el: row, ts: event.ts || null });
        });
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

    function loadData() {
        data = normalizeData(getData());
        if (!data || !Array.isArray(data.tracks) || !data.tracks.length) {
            return false;
        }
        var allTs = [];
        data.tracks.forEach(function (track) {
            (track.points || []).forEach(function (point) {
                if (point.ts) allTs.push(parseFloat(point.ts));
            });
        });
        if (!allTs.length) return false;
        startTs = Math.min.apply(null, allTs);
        endTs = Math.max.apply(null, allTs);
        durationSeconds = Math.max(1, data.durationSeconds || (endTs - startTs));
        data.distanceMeters = data.distanceMeters || data.tracks.reduce(function (sum, track) {
            return sum + (parseFloat(track.distanceMeters) || 0);
        }, 0);
        data.totalMinutes = data.totalMinutes || Math.ceil(durationSeconds / 60);
        eventMarkers = [];
        timelineEntries = [];
        markers = [];
        polylines = [];
        if (map) {
            map.remove();
            map = null;
        }
        initMap();
        renderLegend();
        renderTimeline();
        reset();
        return true;
    }

    if (standalone) {
        if (loadData() && autoplay) {
            setPlaying(true);
        }
    } else {
        modalEl.addEventListener('shown.bs.modal', function () {
            loadData();
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            setPlaying(false);
            if (map) {
                map.remove();
                map = null;
                markers = [];
                polylines = [];
                eventMarkers = [];
                timelineEntries = [];
            }
        });
    }

    document.getElementById(elIds.playPause).addEventListener('click', function () {
        if (!data) return;
        if (elapsedMs / DURATION_MS >= 1) {
            reset();
        }
        setPlaying(!playing);
    });

    document.getElementById(elIds.restart).addEventListener('click', function () {
        if (!data) return;
        setPlaying(false);
        reset();
    });

    document.getElementById(elIds.scrubber).addEventListener('input', function (e) {
        if (!data) return;
        setPlaying(false);
        elapsedMs = parseFloat(e.target.value) * DURATION_MS;
        updateFrame(parseFloat(e.target.value));
    });

    return {
        reload: function () {
            setPlaying(false);
            if (loadData() && autoplay) {
                setPlaying(true);
            }
        }
    };
}

window.initRideReplayController = initRideReplayController;

document.addEventListener('DOMContentLoaded', function () {
    var singleModal = document.getElementById('rideReplayModal');
    if (singleModal) {
        initRideReplayController({
            modalEl: singleModal,
            elIds: {
                map: 'rideReplayMap',
                km: 'rideReplayKm',
                time: 'rideReplayTime',
                scrubber: 'rideReplayScrubber',
                playPause: 'rideReplayPlayPause',
                restart: 'rideReplayRestart',
                legend: 'rideReplayLegend',
                timeline: 'rideReplayTimeline'
            },
            getData: function () { return window.easyRideReplayData || null; }
        });
    }
});
