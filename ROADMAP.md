# EasyRide — Roadmap & Release Log

Κατάσταση: **6 Ιουλίου 2026**. Καταγράφει τι έχει γίνει deploy (production releases στο [TheoSfak/easyrider](https://github.com/TheoSfak/easyrider)) και τι μένει.

---

## Deployed

### v3.81.6 — Πολυήμερες Δράσεις: Φάση 3 (Live Ημέρα σε Ride Mode & Ops Dashboard) — ΟΛΟΚΛΗΡΩΘΗΚΕ
- Το Ride Mode ([ride-mode.php](ride-mode.php)) και το Επιχειρησιακό Dashboard ([ops-dashboard.php](ops-dashboard.php)) επιλέγουν πλέον αυτόματα και δείχνουν στον live χάρτη τη διαδρομή της **σημερινής** ημέρας για πολυήμερες δράσεις, με μικρό badge (π.χ. «Ηράκλειο -> Σητεία · 06/07») δίπλα στο χρονοδιάγραμμα ώστε να φαίνεται ποια ημέρα εμφανίζεται. Fallback στην πλησιέστερη ημέρα αν ανοιχτεί εκτός εύρους ημερομηνιών της δράσης.
- Μονοήμερες δράσεις παραμένουν αναλλοίωτες.
- Αυτό ολοκληρώνει το τριφασικό project πολυήμερων δράσεων (Φάσεις 1-3).
- Spec: [docs/superpowers/specs/2026-07-06-multiday-ride-live-day-design.md](docs/superpowers/specs/2026-07-06-multiday-ride-live-day-design.md)

### v3.81.5 — Πολυήμερες Δράσεις: Φάση 2 (Εμφάνιση Ημερήσιου Προγράμματος)
- Η σελίδα δράσης ([mission-view.php](mission-view.php)) εμφανίζει πλέον το ημερήσιο πρόγραμμα (χάρτης διαδρομής, χρονοδιάγραμμα, badges απόστασης/διάρκειας, σημείωση διανυκτέρευσης) για πολυήμερες δράσεις, μέσω tabs ανά ημέρα.
- Μονοήμερες δράσεις παραμένουν αναλλοίωτες.
- Spec: [docs/superpowers/specs/2026-07-06-multiday-mission-view-display-design.md](docs/superpowers/specs/2026-07-06-multiday-mission-view-display-design.md)

### v3.81.4 — Πολυήμερες Δράσεις: Φάση 1 (Data Model & Δημιουργία)
- Νέος πίνακας `mission_days`: σε μια πολυήμερη δράση (π.χ. 3-ήμερη εκδρομή), ο διαχειριστής σχεδιάζει διαφορετική διαδρομή + σημείωση διανυκτέρευσης ανά ημέρα, στη φόρμα δημιουργίας/επεξεργασίας δράσης ([mission-form.php](mission-form.php)).
- Ανιχνεύεται αυτόματα από το εύρος ημερομηνιών Έναρξης/Λήξης — καμία αλλαγή σε μονοήμερες δράσεις.
- Μαζί με αυτό το release: fix ενός προϋπάρχοντος (άσχετου) JS crash στη σελίδα επεξεργασίας δράσης (`recur_end_date` null reference).
- Spec: [docs/superpowers/specs/2026-07-05-multiday-mission-days-design.md](docs/superpowers/specs/2026-07-05-multiday-mission-days-design.md)

### v3.81.3 — Fullscreen Route Point Editor
- Νέο κουμπί "Μεγάλος χάρτης" στη φόρμα δράσης: ανοίγει fullscreen modal με μεγάλο χάρτη για άνετη σχεδίαση διαδρομής, αντί για τον μικρό ενσωματωμένο χάρτη.
- Δυνατότητα αφαίρεσης ενός λάθος σημείου με κλικ πάνω στο marker του (πέρα από την υπάρχουσα αφαίρεση μέσω λίστας).
- Στο live testing βρέθηκαν και διορθώθηκαν 2 πραγματικά Leaflet bugs (marker click που πρόσθετε διπλό σημείο· race condition μεταξύ popup delete και re-render).
- Spec: [docs/superpowers/specs/2026-07-05-fullscreen-route-editor-design.md](docs/superpowers/specs/2026-07-05-fullscreen-route-editor-design.md)

### v3.81.2 — Ride Replay
- Νέο κουμπί "Ride Replay" σε ολοκληρωμένες δράσεις με καταγεγραμμένη διαδρομή: animated recap μέσα στην εφαρμογή — marker που διανύει τη διαδρομή, live μετρητής χλμ/χρόνου, σημάδια για βασικά συμβάντα (στάσεις, SOS).
- Καθαρά in-app λειτουργία, καμία αλλαγή σε βάση δεδομένων.
- Spec: [docs/superpowers/specs/2026-07-05-ride-replay-design.md](docs/superpowers/specs/2026-07-05-ride-replay-design.md)

### v3.81.1 — Οδηγός Google Maps API Key
- Αναδιπλούμενος οδηγός βήμα-βήμα με ενεργά links προς το Google Cloud Console (δημιουργία project, billing, ενεργοποίηση Routes API, credentials) στις Ρυθμίσεις, δίπλα στο πεδίο Google Maps API Key.

### v3.81.0 και παλαιότερα
- Google Routes API routing με fallback σε ευθεία εκτίμηση, ανανέωση συνδρομών μελών, και τα θεμελιώδη της εφαρμογής (βλ. [README.md](README.md) → Current Release Highlights για πλήρες ιστορικό).

---

## Μένει να γίνει

### Ride Replay — Δημόσιο link κοινοποίησης (χωρίς προγραμματισμένη ημερομηνία)
Αναφέρθηκε ως μελλοντική ιδέα κατά τον σχεδιασμό του Ride Replay: δυνατότητα δημιουργίας δημόσιου (χωρίς login) link ώστε να μοιράζεται το replay μιας δράσης εκτός EasyRide (π.χ. social media, WhatsApp). Requires ξεχωριστό σχεδιασμό (tokens, privacy, expiry) — δεν έχει προγραμματιστεί ακόμα.

### Άλλα ανοιχτά σημεία (μη κρίσιμα, cosmetic)
- Μικρή ασυνέπεια μορφοποίησης ημερομηνίας ανάμεσα στα day-tabs (`d/m`) και στη μπάρα πληροφοριών (πλήρης ελληνική ημερομηνία) στο ημερήσιο πρόγραμμα — καθαρά αισθητικό.
