import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import '../util.dart';

const _dietChoices = [
  ['omnivore', 'Eats everything', 'Meat, fish, dairy and plants'],
  ['pescatarian', 'Fish, but no meat', 'Pescatarian'],
  ['vegetarian', 'Vegetarian', 'No meat or fish'],
  ['vegan', 'Vegan', 'Plants only'],
];

/// Add or edit a child (2 to 17). Children have no calorie target: they eat the shared
/// family dinners, sized for their age, with their allergies respected.
class ChildScreen extends StatefulWidget {
  const ChildScreen({super.key, this.memberId, this.existing});

  final int? memberId;
  final Map<String, dynamic>? existing;

  @override
  State<ChildScreen> createState() => _ChildScreenState();
}

class _ChildScreenState extends State<ChildScreen> {
  final _name = TextEditingController();
  final _exclusionText = TextEditingController();
  DateTime? _birth;
  String _diet = 'omnivore';
  bool _consent = false;
  final List<Map<String, String>> _exclusions = [];

  /// Food notes saved from swapped dinners; only sent back when they were loaded.
  final List<String> _notes = [];
  bool _notesLoaded = false;

  Map<String, String> _errors = {};
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final e = widget.existing;
    if (e == null) return;
    _name.text = (e['name'] ?? '').toString();
    _birth = DateTime.tryParse(e['birth_date']?.toString() ?? '');
    _diet = (e['diet_type'] ?? 'omnivore').toString();
    _consent = e['consent_at'] != null;
    final ex = e['exclusions'];
    if (ex is List) {
      for (final item in ex) {
        if (item is Map) {
          _exclusions.add({'name': item['name'].toString(), 'kind': item['kind'].toString()});
        }
      }
    }
    final prefs = e['preferences'];
    if (prefs is List) {
      _notesLoaded = true;
      for (final p in prefs) {
        final n = p is Map ? p['note'] : p;
        if (n != null && n.toString().trim().isNotEmpty) _notes.add(n.toString());
      }
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _exclusionText.dispose();
    super.dispose();
  }

  String get _who => _name.text.trim().isEmpty ? 'this child' : _name.text.trim();

  Future<void> _pickBirthDate() async {
    final now = DateTime.now();
    final first = DateTime(now.year - 18, now.month, now.day);
    var initial = _birth ?? DateTime(now.year - 8, now.month, now.day);
    if (initial.isBefore(first)) initial = first;
    if (initial.isAfter(now)) initial = now;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: first,
      lastDate: now,
      helpText: 'Date of birth',
    );
    if (picked != null) setState(() => _birth = picked);
  }

  void _addExclusion(String kind) {
    final name = _exclusionText.text.trim();
    if (name.isEmpty) return;
    final exists = _exclusions
        .any((e) => e['name']!.toLowerCase() == name.toLowerCase() && e['kind'] == kind);
    if (!exists) setState(() => _exclusions.add({'name': name, 'kind': kind}));
    _exclusionText.clear();
  }

  Future<void> _save() async {
    // Light checks first; the server checks again.
    final errors = <String, String>{};
    if (_name.text.trim().isEmpty) errors['name'] = 'Please enter a name.';
    if (_birth == null) errors['birth_date'] = 'Please choose a date of birth.';
    if (!_consent) errors['consent'] = 'Please confirm that you are this child\'s parent or guardian.';
    if (errors.isNotEmpty) {
      setState(() {
        _errors = errors;
        _error = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _errors = {};
    });
    final payload = <String, dynamic>{
      'kind': 'child',
      'name': _name.text.trim(),
      'birth_date': dateOnly(_birth!),
      'diet_type': _diet,
      'consent': _consent,
      'exclusions': _exclusions,
      if (_notesLoaded) 'preferences': _notes,
    };
    try {
      if (widget.memberId == null) {
        await Api.instance.createMember(payload);
      } else {
        await Api.instance.updateMember(widget.memberId!, payload);
      }
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      final fields = e.extra['fields'];
      setState(() {
        _error = e.message;
        _errors = fields is Map
            ? fields.map((k, v) => MapEntry(k.toString(), v.toString()))
            : {};
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _errorText(String? text) {
    if (text == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 6, bottom: 4),
      child: Text(text, style: const TextStyle(color: AppColors.error, fontSize: 16)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.memberId != null;
    return Scaffold(
      appBar: appBar(editing ? 'Edit $_who' : 'Add a child', backLabel: 'Cancel'),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const StepHeading('About the child',
                        subtitle:
                            'Children eat the family dinners, with portions sized for their age. We do not set calorie targets for children.'),
                    TextField(
                      controller: _name,
                      textCapitalization: TextCapitalization.words,
                      style: const TextStyle(fontSize: 20),
                      onChanged: (_) => setState(() {}),
                      decoration: fieldDecoration('Name', error: _errors['name']),
                    ),
                    const SizedBox(height: 16),
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
                    _errorText(_errors['birth_date']),
                    const SizedBox(height: 20),
                    const Text('What do they eat?',
                        style: TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 10),
                    for (final c in _dietChoices)
                      ChoiceTile(
                        label: c[1],
                        subtitle: c[2],
                        selected: _diet == c[0],
                        onTap: () => setState(() => _diet = c[0]),
                      ),
                    const SizedBox(height: 12),
                    const Text('Allergies and foods to leave out',
                        style: TextStyle(fontSize: 19, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 4),
                    const Text('Type one food, then tell us if it is an allergy or just a dislike.',
                        style: TextStyle(color: AppColors.muted, fontSize: 16)),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _exclusionText,
                      style: const TextStyle(fontSize: 20),
                      decoration: fieldDecoration('For example: peanuts, eggs, mushrooms',
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
                                  color: e['kind'] == 'allergy'
                                      ? AppColors.error
                                      : AppColors.line),
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
                      const Text(
                          'What you told us when you swapped a dinner. Remove any that no longer apply.',
                          style: TextStyle(color: AppColors.muted, fontSize: 16)),
                      const SizedBox(height: 10),
                      for (final n in List<String>.from(_notes))
                        Padding(
                          padding: const EdgeInsets.only(bottom: 8),
                          child: AppCard(
                            padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
                            child: Row(
                              children: [
                                Expanded(
                                    child: Text(n,
                                        style: const TextStyle(fontSize: 17, height: 1.35))),
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
                      _errorText(_errors['preferences']),
                    ],
                    const SizedBox(height: 20),
                    AppCard(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      child: CheckboxListTile(
                        contentPadding: const EdgeInsets.symmetric(horizontal: 8),
                        controlAffinity: ListTileControlAffinity.leading,
                        activeColor: AppColors.accent,
                        checkColor: AppColors.onAccent,
                        value: _consent,
                        onChanged: (v) => setState(() => _consent = v ?? false),
                        title: Text(
                            'I am $_who\'s parent or guardian, and I agree to their details being stored so that meal plans can be built. '
                            'I understand that NutriPlan gives general nutrition guidance, not medical advice.'),
                      ),
                    ),
                    _errorText(_errors['consent']),
                    if (_error != null) ...[
                      const SizedBox(height: 16),
                      Banner2(_error!, isError: true),
                    ],
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
              child: FilledButton(
                onPressed: _busy ? null : _save,
                child: _busy
                    ? const ButtonSpinner()
                    : Text(editing ? 'Save changes' : 'Add to my family'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
