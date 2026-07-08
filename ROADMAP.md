# EasyRide — Roadmap & Release Log

Κατάσταση: **6 Ιουλίου 2026**. Καταγράφει τι έχει γίνει deploy (production releases στο [TheoSfak/easyrider](https://github.com/TheoSfak/easyrider)) και τι μένει.

---

## Deployed

### v3.87.0 — Κατάσταση "Στην Πλοήγηση" στο Ride Mode
- Όταν ένας αναβάτης πατάει "Πλοήγηση" στο Ride Mode, το Google Maps ανοίγει σε νέο tab και το στίγμα GPS συχνά σταματά σιωπηλά (περιορισμός των mobile browsers σε background tabs). Τώρα ο υπεύθυνος βλέπει "🧭 Στην πλοήγηση" αντί για ασαφές "Stale", χωρίς μόνιμη καταγραφή — και ο αναβάτης παίρνει ένα διακριτικό μήνυμα να αφήσει την οθόνη ανοιχτή όταν επιστρέψει μόνος του στην εφαρμογή.
- Spec: [docs/superpowers/specs/2026-07-08-navigating-status-design.md](docs/superpowers/specs/2026-07-08-navigating-status-design.md)

### v3.86.2 — Κρυφή Καρτέλα Αποθέματος στις Ρυθμίσεις
- Η καρτέλα "Απόθεμα" στη σελίδα Ρυθμίσεων εμφανιζόταν πάντα, ακόμα κι όταν η λειτουργία αποθέματος είναι απενεργοποιημένη (`inventory_nav_enabled = 0`, το ίδιο flag που ήδη κρύβει τον σύνδεσμο στο πλαϊνό μενού). Τώρα η καρτέλα και το περιεχόμενό της κρύβονται με το ίδιο flag.

### v3.86.1 — Ενιαία Μορφή Ημερομηνίας στο Ημερήσιο Πρόγραμμα
- Η μπάρα πληροφοριών του ημερήσιου προγράμματος (πολυήμερες δράσεις) έδειχνε την ημερομηνία σε πλήρη μορφή ("Τετάρτη, 1 Ιουλίου 2026"), ενώ τα day-tabs έδειχναν σύντομη μορφή ("01/07") — ασυνέπεια. Τώρα και τα δύο μοιράζονται την ίδια μορφή αριθμών ημερομηνίας (π.χ. "Τετάρτη 01/07").

### v3.86.0 — Ride Replay Public Share Link
- Οι υπεύθυνοι δράσεων μπορούν πλέον να δημιουργήσουν έναν δημόσιο σύνδεσμο (χωρίς απαίτηση σύνδεσης) που εμφανίζει το Ride Replay μιας ολοκληρωμένης δράσης — με αυτόματη αναπαραγωγή, και εναλλαγή ημερών για πολυήμερες δράσεις — για κοινοποίηση εκτός EasyRide (social media, WhatsApp). Ο σύνδεσμος παραμένει ενεργός μέχρι να ανακληθεί χειροκίνητα.
- Spec: [docs/superpowers/specs/2026-07-07-ride-replay-share-link-design.md](docs/superpowers/specs/2026-07-07-ride-replay-share-link-design.md)

### v3.85.0 — Ride Replay Visibility Fix
- Το Ride Replay (animated recap διαδρομής για ολοκληρωμένες δράσεις) δεν εμφανιζόταν ποτέ στην πράξη: απαιτούσε αποκλειστικά τη διαδρομή μέσω Google Routes API. Τώρα κάνει fallback στα χειροκίνητα σημεία διαδρομής όταν δεν υπάρχει snapped γεωμετρία, και οι πολυήμερες δράσεις αποκτούν ξεχωριστό κουμπί Ride Replay ανά ημέρα, με τη διαδρομή και τα συμβάντα της συγκεκριμένης ημέρας.
- Spec: [docs/superpowers/specs/2026-07-07-ride-replay-visibility-fix-design.md](docs/superpowers/specs/2026-07-07-ride-replay-visibility-fix-design.md)

### v3.84.0 — "Σκέλος"/"Βάρδια" → "Κύκλος Εγγραφών"
- Ενοποίηση δύο ασυνεπών όρων ("Σκέλος", "Βάρδια") που σήμαιναν το ίδιο πράγμα (χρονικό παράθυρο εγγραφής μελών με όριο συμμετεχόντων) σε έναν: "Κύκλος Εγγραφών". Καθαρά αλλαγή λεκτικού σε όλη την εφαρμογή (σελίδες, emails, ειδοποιήσεις, achievements) — καμία αλλαγή στη λειτουργικότητα, στα ονόματα αρχείων/πινάκων, ή στα URLs.
- Spec: [docs/superpowers/specs/2026-07-07-shift-to-signup-cycle-rename-design.md](docs/superpowers/specs/2026-07-07-shift-to-signup-cycle-rename-design.md)

### v3.83.0 — Μάρκα/Μοντέλο Μηχανής Μέλους
- Νέο πεδίο στη φόρμα/προφίλ μέλους για καταγραφή μάρκας/μοντέλου μηχανής: αναζητήσιμο dropdown (`<datalist>`) με δυνατότητα ελεύθερης καταχώρησης αν δεν υπάρχει η μάρκα/μοντέλο. Νέες καταχωρήσεις μπαίνουν σε εκκρεμότητα μέχρι έγκριση από διαχειριστή συστήματος (Ρυθμίσεις), μετά την οποία εμφανίζονται ως προτάσεις σε όλους.
- Αφαιρέθηκε η κάρτα "Ιστορικό Εξετάσεων & Κουίζ" από το προφίλ μέλους.
- Spec: [docs/superpowers/specs/2026-07-06-motorcycle-brand-model-design.md](docs/superpowers/specs/2026-07-06-motorcycle-brand-model-design.md)

### v3.82.0 — Volunteer → Member Rename + Αφαίρεση Πεδίων Στολής/Μητρώων
- Πλήρης μετονομασία "volunteer"/"Εθελοντής" → "member"/"Μέλος" σε όλη την εφαρμογή: πίνακες/στήλες/indexes βάσης δεδομένων, ονόματα αρχείων και URLs (τα παλιά URLs κάνουν αυτόματο 301 redirect), κάθε PHP identifier/query, και όλα τα ελληνικά/αγγλικά κείμενα UI — με προσεκτική τήρηση γραμματικού γένους (το "εθελοντής" είναι αρσενικό, το "μέλος" ουδέτερο). Ο εσωτερικός guard constant της εφαρμογής και οι raw τιμές ρόλου/τύπου στη βάση παραμένουν αμετάβλητες σκόπιμα, καθώς είναι αόρατες στον χρήστη.
- Αφαιρέθηκαν τα ξεπερασμένα πεδία μεγέθους στολής (παντελόνι/χιτώνιο/μπλούζα/fleece) και τα μητρώα ΕΠΙΔΡΑΣΙΣ/ΓΓΠΠ από τη φόρμα/προφίλ μέλους και το CSV import/export, αντικαθιστώνται από ένα προαιρετικό πεδίο «Αρ. Μητρώου Λέσχης».
- Spec: [docs/superpowers/specs/2026-07-06-volunteer-to-member-rename-design.md](docs/superpowers/specs/2026-07-06-volunteer-to-member-rename-design.md)

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
