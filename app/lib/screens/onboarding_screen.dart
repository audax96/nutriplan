import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import '../util.dart';
import 'home_gate.dart';

const _stepCount = 6;

const _activityChoices = [
  ['sedentary', 'Mostly sitting', 'Desk job, little exercise'],
  ['light', 'Lightly active', 'Exercise 1 to 3 days a week'],
  ['moderate', 'Moderately active', 'Exercise 3 to 5 days a week'],
  ['active', 'Very active', 'Exercise 6 to 7 days a week'],
  ['very_active', 'Extremely active', 'Hard training or a physical job'],
];

const _dietChoices = [
  ['omnivore', 'I eat everything', 'Meat, fish, dairy and plants'],
  ['pescatarian', 'Fish, but no meat', 'Pescatarian'],
  ['vegetarian', 'Vegetarian', 'No meat or fish'],
  ['vegan', 'Vegan', 'Plants only'],
];

const _goalChoices = [
  ['lose', 'Lose weight'],
  ['maintain', 'Stay the same'],
  ['gain', 'Gain weight'],
];

const _paceChoices = [
  [0.25, 'Gentle', '0.25 kg a week. Easiest to keep up.'],
  [0.5, 'Steady', '0.5 kg a week. A good balance.'],
  [0.75, 'Faster', '0.75 kg a week. Harder to keep up.'],
];

/// A short step-by-step setup: body stats, goal, food, drinks, consent.
/// Used for the account holder's first setup, for editing a profile later, and (with
/// [addingMember] or [memberId]) for other adults in the family.
class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen(
      {super.key, this.existing, this.memberId, this.addingMember = false, this.initialStep = 0});

  final Map<String, dynamic>? existing;

  /// Set when editing a family member through the members API (otherwise the account
  /// holder's own profile is used).
  final int? memberId;

  /// True when adding a new adult to the family.
  final bool addingMember;

  /// Opens on this step (2 = goal and daily targets), for "Change my goals".
  final int initialStep;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _height = TextEditingController();
  final _weight = TextEditingController();
  final _goalWeight = TextEditingController();
  final _calories = TextEditingController();
  final _protein = TextEditingController();
  final _carbs = TextEditingController();
  final _fat = TextEditingController();
  final _exclusionText = TextEditingController();
  final _name = TextEditingController();

  int _step = 0;
  String? _sex;
  DateTime? _birth;
  String _activity = 'light';
  String _goalType = 'maintain';
  double _rate = 0.5;
  String _diet = 'omnivore';
  bool _pregnant = false;
  bool _consent = false;
  final List<Map<String, String>> _exclusions = [];

  /// Food notes saved from swapped meals ("Did not like the texture ..."). Only sent back
  /// when they were loaded, so an edit can never wipe notes the screen did not show.
  final List<String> _notes = [];
  bool _notesLoaded = false;

  // Usual drinks: what the person already drinks. Their calories come off the daily target.
  final List<Map<String, dynamic>> _usualDrinks = [];
  List<Map<String, dynamic>> _drinkOptions = [];
  String? _drinkChoice; // a drink key, or '_custom'
  bool _customAlcohol = false;
  final _servings = TextEditingController();
  final _customName = TextEditingController();
  final _customKcal = TextEditingController();

  Map<String, String> _errors = {};
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadDrinkOptions();
    final first = widget.initialStep;
    _step = first < 0 ? 0 : (first >= _stepCount ? _stepCount - 1 : first);
    final e = widget.existing;
    if (e == null) return;
    final prefs = e['preferences'];
    if (prefs is List) {
      _notesLoaded = true;
      for (final p in prefs) {
        final n = p is Map ? p['note'] : p;
        if (n != null && n.toString().trim().isNotEmpty) _notes.add(n.toString());
      }
    }
    _name.text = (e['name'] ?? '').toString();
    _sex = e['sex']?.toString();
    _birth = DateTime.tryParse(e['birth_date']?.toString() ?? '');
    _height.text = fmtNum(e['height_cm']);
    _weight.text = fmtNum(e['weight_kg']);
    _goalWeight.text = fmtNum(e['goal_weight_kg']);
    _calories.text = fmtNum(e['calorie_override']);
    _protein.text = fmtNum(e['target_protein_g']);
    _carbs.text = fmtNum(e['target_carb_g']);
    _fat.text = fmtNum(e['target_fat_g']);
    _activity = (e['activity_level'] ?? 'light').toString();
    _goalType = (e['goal_type'] ?? 'maintain').toString();
    final r = toDouble(e['weekly_rate_kg']) ?? 0.5;
    _rate = r <= 0.375 ? 0.25 : (r <= 0.625 ? 0.5 : 0.75);
    _diet = (e['diet_type'] ?? 'omnivore').toString();
    final preg = e['pregnant_or_breastfeeding'].toString();
    _pregnant = preg == '1' || preg == 'true';
    _consent = e['consent_at'] != null;
    final ud = e['usual_drinks'];
    if (ud is List) {
      for (final item in ud) {
        if (item is Map) {
          final alc = item['is_alcohol'].toString();
          _usualDrinks.add({
            'drink_key': item['drink_key'],
            'name': item['name'].toString(),
            'kcal_per_serving': toDouble(item['kcal_per_serving']),
            'is_alcohol': alc == '1' || alc == 'true',
            'servings_per_week': toDouble(item['servings_per_week']) ?? 0,
          });
        }
      }
    }
    final ex = e['exclusions'];
    if (ex is List) {
      for (final item in ex) {
        if (item is Map) {
          _exclusions.add({
            'name': item['name'].toString(),
            'kind': item['kind'].toString(),
          });
        }
      }
    }
  }

  @override
  void dispose() {
    for (final c in [
      _height, _weight, _goalWeight, _calories,
      _protein, _carbs, _fat, _exclusionText, _servings, _customName, _customKcal, _name,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  bool get _viaMemberApi => widget.addingMember || widget.memberId != null;

  /// True for the account holder's own form (name optional, first-person wording).
  bool get _ownerForm =>
      !widget.addingMember &&
      (widget.memberId == null || '${widget.existing?['is_owner']}' == '1');

  String get _who => _name.text.trim().isEmpty ? 'this person' : _name.text.trim();

  Future<void> _loadDrinkOptions() async {
    try {
      final options = await Api.instance.drinks();
      if (mounted) setState(() => _drinkOptions = options);
    } on ApiException {
      // The picker stays empty; custom drinks still work.
    }
  }

  // ---------- navigation ----------

  /// Light checks so the person gets help on the step they are on. The server checks again.
  bool _validateStep(int step) {
    final errors = <String, String>{};
    if (step == 0) {
      if (!_ownerForm && _name.text.trim().isEmpty) errors['name'] = 'Please enter a name.';
      if (_sex == null) errors['sex'] = 'Please choose female or male.';
      if (_birth == null) errors['birth_date'] = 'Please choose a date of birth.';
      final h = toDouble(_height.text);
      if (h == null || h <= 0) errors['height_cm'] = 'Please enter a height in cm.';
      final w = toDouble(_weight.text);
      if (w == null || w <= 0) errors['weight_kg'] = 'Please enter a weight in kg.';
    }
    if (step == 4 &&
        (_drinkChoice != null || _servings.text.trim().isNotEmpty) &&
        !_addDrink()) {
      return false;
    }
    if (errors.isNotEmpty) {
      setState(() => _errors = errors);
      return false;
    }
    setState(() => _errors = {});
    return true;
  }

  void _next() {
    if (!_validateStep(_step)) return;
    if (_step == _stepCount - 1) {
      _save();
    } else {
      setState(() => _step++);
    }
  }

  void _back() {
    if (_step > 0) setState(() => _step--);
  }

  int _stepFor(String key) {
    switch (key) {
      case 'name':
      case 'sex':
      case 'birth_date':
      case 'height_cm':
      case 'weight_kg':
        return 0;
      case 'activity_level':
        return 1;
      case 'goal_type':
      case 'goal_weight_kg':
      case 'weekly_rate_kg':
      case 'calorie_override':
      case 'macros':
      case 'target_protein_g':
      case 'target_carb_g':
      case 'target_fat_g':
        return 2;
      case 'diet_type':
      case 'exclusions':
      case 'preferences':
        return 3;
      case 'usual_drinks':
      case 'custom_kcal':
        return 4;
      default:
        return 5;
    }
  }

  // ---------- drinks and exclusions ----------

  /// Adds the drink being edited to the list. Returns false (and shows why) if it is incomplete.
  bool _addDrink() {
    final servings = toDouble(_servings.text);
    if (_drinkChoice == null || servings == null || servings <= 0) {
      setState(() => _errors = {
            ..._errors,
            'usual_drinks': 'Please choose a drink and say how many you have each week.',
          });
      return false;
    }
    Map<String, dynamic> entry;
    if (_drinkChoice == '_custom') {
      final name = _customName.text.trim();
      final kcal = toDouble(_customKcal.text);
      if (name.isEmpty || kcal == null || kcal <= 0) {
        setState(() => _errors = {
              ..._errors,
              'usual_drinks': 'Please enter the drink\'s name and its calories per serving.',
            });
        return false;
      }
      entry = {
        'drink_key': null,
        'name': name,
        'kcal_per_serving': kcal,
        'is_alcohol': _customAlcohol,
        'servings_per_week': servings,
      };
    } else {
      final option = _drinkOptions.firstWhere((o) => o['key'] == _drinkChoice);
      entry = {
        'drink_key': _drinkChoice,
        'name': option['name'].toString(),
        'kcal_per_serving': null,
        'is_alcohol': false,
        'servings_per_week': servings,
      };
    }
    setState(() {
      _errors = Map.of(_errors)..remove('usual_drinks');
      _usualDrinks.add(entry);
      _servings.clear();
      _customName.clear();
      _customKcal.clear();
      _customAlcohol = false;
      _drinkChoice = null;
    });
    return true;
  }

  void _addExclusion(String kind) {
    final name = _exclusionText.text.trim();
    if (name.isEmpty) return;
    final exists = _exclusions
        .any((e) => e['name']!.toLowerCase() == name.toLowerCase() && e['kind'] == kind);
    if (!exists) {
      setState(() => _exclusions.add({'name': name, 'kind': kind}));
    }
    _exclusionText.clear();
  }

  Future<void> _pickBirthDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _birth ?? DateTime(1990, 1, 1),
      firstDate: DateTime(1920),
      lastDate: DateTime.now(),
      helpText: 'Date of birth',
    );
    if (picked != null) setState(() => _birth = picked);
  }

  // ---------- save ----------

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
      _errors = {};
    });
    final payload = <String, dynamic>{
      'kind': 'adult',
      'name': _name.text.trim(),
      'sex': _sex,
      'birth_date': _birth == null ? null : dateOnly(_birth!),
      'height_cm': toDouble(_height.text),
      'weight_kg': toDouble(_weight.text),
      'activity_level': _activity,
      'goal_type': _goalType,
      'goal_weight_kg': toDouble(_goalWeight.text),
      'weekly_rate_kg': _goalType == 'maintain' ? 0.5 : _rate,
      'calorie_override': toDouble(_calories.text)?.round(),
      'target_protein_g': toDouble(_protein.text)?.round(),
      'target_carb_g': toDouble(_carbs.text)?.round(),
      'target_fat_g': toDouble(_fat.text)?.round(),
      'diet_type': _diet,
      'pregnant_or_breastfeeding': _pregnant,
      'consent': _consent,
      'exclusions': _exclusions,
      if (_notesLoaded) 'preferences': _notes,
      'usual_drinks': _usualDrinks,
    };
    try {
      if (_viaMemberApi) {
        if (widget.addingMember) {
          await Api.instance.createMember(payload);
        } else {
          await Api.instance.updateMember(widget.memberId!, payload);
        }
        if (!mounted) return;
        Navigator.of(context).pop(true);
        return;
      }
      await Api.instance.saveProfile(payload);
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const HomeGate()),
        (_) => false,
      );
    } on ApiException catch (e) {
      final fields = e.extra['fields'];
      final errors = fields is Map
          ? fields.map((k, v) => MapEntry(k.toString(), v.toString()))
          : <String, String>{};
      setState(() {
        _error = e.message;
        _errors = errors;
        if (errors.isNotEmpty) {
          var first = _stepCount - 1;
          for (final k in errors.keys) {
            final s = _stepFor(k);
            if (s < first) first = s;
          }
          _step = first;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ---------- small builders ----------

  Widget _numberField(TextEditingController c, String label, String errorKey,
      {String? suffix, String? helper, ValueChanged<String>? onChanged}) {
    return TextField(
      controller: c,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      onChanged: onChanged,
      style: const TextStyle(fontSize: 20),
      decoration: fieldDecoration(label,
          suffix: suffix, helper: helper, error: _errors[errorKey]),
    );
  }

  Widget _errorText(String? text) {
    if (text == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Text(text, style: const TextStyle(color: AppColors.error, fontSize: 16)),
    );
  }

  /// Explains the gram fields, and shows the calories once all three are filled in.
  String _macroHint() {
    final p = toDouble(_protein.text);
    final c = toDouble(_carbs.text);
    final f = toDouble(_fat.text);
    if (p != null && c != null && f != null) {
      final kcal = ((4 * p + 4 * c + 9 * f) / 10).round() * 10;
      return 'That is about $kcal kcal a day. With all three set, your daily calories come from these, so leave Daily calories blank.';
    }
    return 'Fill in any you care about and leave the rest blank; we work them out to fit your calories.';
  }

  // ---------- steps ----------

  Widget _stepAboutYou() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StepHeading(_ownerForm ? 'About you' : 'About $_who',
            subtitle: _ownerForm ? 'We use this to work out how much energy you need each day.' : 'We use this to work out how much energy $_who needs each day.'),
        TextField(
          controller: _name,
          textCapitalization: TextCapitalization.words,
          style: const TextStyle(fontSize: 20),
          onChanged: (_) => setState(() {}),
          decoration: fieldDecoration(_ownerForm ? 'Your name (optional)' : 'Name',
              error: _errors['name']),
        ),
        const SizedBox(height: 20),
        ChoiceTile(
          label: 'Female',
          selected: _sex == 'female',
          onTap: () => setState(() => _sex = 'female'),
        ),
        ChoiceTile(
          label: 'Male',
          selected: _sex == 'male',
          onTap: () => setState(() => _sex = 'male'),
        ),
        _errorText(_errors['sex']),
        const SizedBox(height: 6),
        AppCard(
          onTap: _pickBirthDate,
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Date of birth',
                        style: TextStyle(color: AppColors.muted, fontSize: 16)),
                    const SizedBox(height: 2),
                    Text(_birth == null ? 'Tap to choose a date' : prettyDate(_birth!),
                        style: const TextStyle(fontSize: 20)),
                  ],
                ),
              ),
              const Icon(Icons.calendar_today_outlined, color: AppColors.muted),
            ],
          ),
        ),
        const SizedBox(height: 4),
        _errorText(_errors['birth_date']),
        const SizedBox(height: 12),
        _numberField(_height, 'Height', 'height_cm', suffix: 'cm'),
        const SizedBox(height: 16),
        _numberField(_weight, 'Weight', 'weight_kg', suffix: 'kg'),
      ],
    );
  }

  Widget _stepActivity() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StepHeading(_ownerForm ? 'How active are you?' : 'How active is $_who?',
            subtitle: 'Think about a normal week.'),
        for (final c in _activityChoices)
          ChoiceTile(
            label: c[1],
            subtitle: c[2],
            selected: _activity == c[0],
            onTap: () => setState(() => _activity = c[0]),
          ),
        _errorText(_errors['activity_level']),
      ],
    );
  }

  Widget _stepGoal() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StepHeading(_ownerForm ? 'What is your goal?' : 'What is $_who\'s goal?'),
        for (final c in _goalChoices)
          ChoiceTile(
            label: c[1],
            selected: _goalType == c[0],
            onTap: () => setState(() => _goalType = c[0]),
          ),
        if (_goalType != 'maintain') ...[
          const SizedBox(height: 12),
          _numberField(_goalWeight, 'Goal weight (optional)', 'goal_weight_kg',
              suffix: 'kg'),
          const SizedBox(height: 20),
          const Text('How fast do you want to get there?', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
          const SizedBox(height: 10),
          for (final p in _paceChoices)
            ChoiceTile(
              label: p[1] as String,
              subtitle: p[2] as String,
              selected: _rate == (p[0] as double),
              onTap: () => setState(() => _rate = p[0] as double),
            ),
          Text('We never set calories below a safe minimum.',
              style: Theme.of(context).textTheme.bodySmall),
        ],
        const SizedBox(height: 24),
        // Always visible (testers could not find it inside a folded section).
        AppCard(
          padding: const EdgeInsets.fromLTRB(18, 18, 18, 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.track_changes, color: AppColors.accent, size: 28),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(_ownerForm ? 'Your daily targets' : 'Daily targets for $_who',
                        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                  ),
                ],
              ),
              const SizedBox(height: 6),
              const Text(
                  'Optional. Leave these blank and we will work them out from the details above.',
                  style: TextStyle(color: AppColors.muted, fontSize: 16)),
              const SizedBox(height: 16),
              _numberField(_calories, 'Daily calories', 'calorie_override', suffix: 'kcal'),
              const SizedBox(height: 18),
              const Text('Protein, carbs and fat (grams per day)',
                  style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                      child: _numberField(_protein, 'Protein', 'target_protein_g',
                          suffix: 'g', onChanged: (_) => setState(() {}))),
                  const SizedBox(width: 8),
                  Expanded(
                      child: _numberField(_carbs, 'Carbs', 'target_carb_g',
                          suffix: 'g', onChanged: (_) => setState(() {}))),
                  const SizedBox(width: 8),
                  Expanded(
                      child: _numberField(_fat, 'Fat', 'target_fat_g',
                          suffix: 'g', onChanged: (_) => setState(() {}))),
                ],
              ),
              _errorText(_errors['macros']),
              const SizedBox(height: 6),
              Text(_macroHint(), style: const TextStyle(color: AppColors.muted, fontSize: 16)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _stepFood() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StepHeading(_ownerForm ? 'How do you eat?' : 'How does $_who eat?'),
        for (final c in _dietChoices)
          ChoiceTile(
            label: c[1],
            subtitle: c[2],
            selected: _diet == c[0],
            onTap: () => setState(() => _diet = c[0]),
          ),
        const SizedBox(height: 20),
        const Text('Allergies and foods to leave out',
            style: TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
        const SizedBox(height: 4),
        const Text('Type one food, then tell us if it is an allergy or just a dislike.',
            style: TextStyle(color: AppColors.muted, fontSize: 16)),
        const SizedBox(height: 12),
        TextField(
          controller: _exclusionText,
          style: const TextStyle(fontSize: 20),
          decoration: fieldDecoration('For example: peanuts, dairy, mushrooms',
              error: _errors['exclusions']),
          onSubmitted: (_) => _addExclusion('allergy'),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: () => _addExclusion('allergy'),
                child: const Text('Add as allergy'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton(
                onPressed: () => _addExclusion('dislike'),
                child: const Text('Add as dislike'),
              ),
            ),
          ],
        ),
        if (_exclusions.isNotEmpty) ...[
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final e in _exclusions)
                Chip(
                  backgroundColor: AppColors.surfaceHigh,
                  side: BorderSide(
                      color: e['kind'] == 'allergy' ? AppColors.error : AppColors.line),
                  avatar: Icon(
                    e['kind'] == 'allergy' ? Icons.warning_amber : Icons.block,
                    size: 18,
                    color: e['kind'] == 'allergy' ? AppColors.error : AppColors.muted,
                  ),
                  label: Text(
                    e['kind'] == 'allergy' ? '${e['name']} (allergy)' : e['name']!,
                    style: const TextStyle(fontSize: 17, color: AppColors.text),
                  ),
                  deleteIconColor: AppColors.muted,
                  deleteIcon: const Icon(Icons.cancel, size: 26),
                  onDeleted: () => setState(() => _exclusions.remove(e)),
                ),
            ],
          ),
        ],
        if (_notes.isNotEmpty) ...[
          const SizedBox(height: 24),
          const Text('Food notes',
              style: TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          const Text('What you told us when you swapped a meal. Remove any that no longer apply.',
              style: TextStyle(color: AppColors.muted, fontSize: 16)),
          const SizedBox(height: 10),
          for (final n in List<String>.from(_notes))
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: AppCard(
                padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
                child: Row(
                  children: [
                    Expanded(child: Text(n, style: const TextStyle(fontSize: 17, height: 1.35))),
                    IconButton(
                      tooltip: 'Remove this note',
                      iconSize: 26,
                      onPressed: () => setState(() => _notes.remove(n)),
                      icon: const Icon(Icons.close, color: AppColors.muted),
                    ),
                  ],
                ),
              ),
            ),
          if (_errors['preferences'] != null) _errorText(_errors['preferences']),
        ],
      ],
    );
  }

  Widget _stepDrinks() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StepHeading(_ownerForm ? 'Do you have regular drinks?' : 'Does $_who have regular drinks?',
            subtitle:
                'Cooldrinks, sweet coffee and wine all add calories. We take these off the daily total so the meals fit around them. You can skip this step.'),
        if (_usualDrinks.isNotEmpty) ...[
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final d in _usualDrinks)
                Chip(
                  backgroundColor: AppColors.surfaceHigh,
                  side: const BorderSide(color: AppColors.line),
                  label: Text('${d['name']}, ${fmtNum(d['servings_per_week'])} a week',
                      style: const TextStyle(fontSize: 17, color: AppColors.text)),
                  deleteIconColor: AppColors.muted,
                  deleteIcon: const Icon(Icons.cancel, size: 26),
                  onDeleted: () => setState(() => _usualDrinks.remove(d)),
                ),
            ],
          ),
          const SizedBox(height: 16),
        ],
        DropdownButtonFormField<String>(
          value: _drinkChoice,
          isExpanded: true,
          dropdownColor: AppColors.surface,
          decoration: fieldDecoration('Choose a drink'),
          items: [
            for (final o in _drinkOptions)
              DropdownMenuItem(
                value: o['key'].toString(),
                child: Text('${o['name']} (${o['serving']}, ${o['kcal']} kcal)',
                    overflow: TextOverflow.ellipsis),
              ),
            const DropdownMenuItem(value: '_custom', child: Text('Something else')),
          ],
          onChanged: (v) => setState(() => _drinkChoice = v),
        ),
        if (_drinkChoice == '_custom') ...[
          const SizedBox(height: 12),
          TextField(
            controller: _customName,
            style: const TextStyle(fontSize: 20),
            decoration: fieldDecoration('Drink name'),
          ),
          const SizedBox(height: 12),
          _numberField(_customKcal, 'Calories per serving', 'custom_kcal', suffix: 'kcal'),
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            activeColor: AppColors.accent,
            checkColor: AppColors.onAccent,
            value: _customAlcohol,
            onChanged: (v) => setState(() => _customAlcohol = v ?? false),
            title: const Text('It contains alcohol'),
          ),
        ],
        const SizedBox(height: 12),
        _numberField(_servings, 'How many a week?', 'usual_drinks',
            helper: 'For example, 7 means one a day. A glass of wine on Saturday and Sunday is 2.'),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: _addDrink,
          icon: const Icon(Icons.add, size: 26),
          label: const Text('Add this drink'),
        ),
      ],
    );
  }

  Widget _stepHealth() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StepHeading(widget.existing != null ? 'Almost done' : 'One last thing'),
        AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          child: CheckboxListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 8),
            controlAffinity: ListTileControlAffinity.leading,
            activeColor: AppColors.accent,
            checkColor: AppColors.onAccent,
            value: _pregnant,
            onChanged: (v) => setState(() => _pregnant = v ?? false),
            title: Text(_ownerForm ? 'I am pregnant or breastfeeding' : '$_who is pregnant or breastfeeding'),
            subtitle: const Text(
                'Needs are different at this time, so we cannot build a plan. Please speak to a doctor or dietitian.',
                style: TextStyle(color: AppColors.muted, fontSize: 16)),
          ),
        ),
        const SizedBox(height: 12),
        AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          child: CheckboxListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 8),
            controlAffinity: ListTileControlAffinity.leading,
            activeColor: AppColors.accent,
            checkColor: AppColors.onAccent,
            value: _consent,
            onChanged: (v) => setState(() => _consent = v ?? false),
            title: Text(_ownerForm
                ? 'I understand that NutriPlan gives general nutrition guidance, not medical advice. '
                    'I agree to my health details being stored so that my plans can be built. I know I can delete them at any time.'
                : 'I have $_who\'s permission to store their health details so that their plan can be built. '
                    'I understand that NutriPlan gives general nutrition guidance, not medical advice.'),
          ),
        ),
        const SizedBox(height: 8),
        _errorText(_errors['consent']),
      ],
    );
  }

  Widget _currentStep() {
    switch (_step) {
      case 0:
        return _stepAboutYou();
      case 1:
        return _stepActivity();
      case 2:
        return _stepGoal();
      case 3:
        return _stepFood();
      case 4:
        return _stepDrinks();
      default:
        return _stepHealth();
    }
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.existing != null;
    final last = _step == _stepCount - 1;
    return Scaffold(
      appBar: appBar(
          widget.addingMember
              ? 'Add an adult'
              : (editing ? (_ownerForm ? 'Edit your details' : 'Edit $_who') : 'Set up your plan'),
          backLabel: Navigator.of(context).canPop() ? 'Cancel' : null),
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: (_step + 1) / _stepCount,
                      minHeight: 6,
                      backgroundColor: AppColors.surfaceHigh,
                      color: AppColors.accent,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text('Step ${_step + 1} of $_stepCount',
                      style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
            Expanded(
              child: SingleChildScrollView(
                key: ValueKey('step$_step'),
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 560),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _currentStep(),
                      if (_error != null) ...[
                        const SizedBox(height: 16),
                        Banner2(_error!, isError: true),
                      ],
                    ],
                  ),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
              child: Row(
                children: [
                  if (_step > 0) ...[
                    Expanded(
                      flex: 2,
                      child: OutlinedButton.icon(
                        onPressed: _busy ? null : _back,
                        icon: const Icon(Icons.arrow_back, size: 26),
                        label: const Text('Back'),
                      ),
                    ),
                    const SizedBox(width: 12),
                  ],
                  Expanded(
                    flex: 3,
                    child: FilledButton(
                      onPressed: _busy ? null : _next,
                      child: _busy
                          ? const ButtonSpinner()
                          : Text(last
                              ? (widget.addingMember
                                  ? 'Add to my family'
                                  : (editing ? 'Save changes' : 'Save and continue'))
                              : (_step == 4 && _usualDrinks.isEmpty && _drinkChoice == null
                                  ? 'Skip'
                                  : 'Continue')),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
