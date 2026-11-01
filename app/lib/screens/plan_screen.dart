import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../print_menu.dart';
import '../ui.dart';
import '../util.dart';
import 'auth_screen.dart';
import 'pantry_screen.dart';

/// The Meals tab: this week's meals, one day at a time.
class PlanScreen extends StatefulWidget {
  const PlanScreen({
    super.key,
    required this.profile,
    this.targets,
    this.familyVersion = 0,
    this.onPlanChanged,
    this.onOpenFamily,
  });

  final Map<String, dynamic> profile;
  final Map<String, dynamic>? targets;

  /// Changes whenever the family is edited elsewhere, so this screen can reload it.
  final int familyVersion;

  /// Called with the id of the plan to show (or null) whenever it changes.
  final ValueChanged<int?>? onPlanChanged;

  /// Asks the shell to switch to the Family tab.
  final VoidCallback? onOpenFamily;

  @override
  State<PlanScreen> createState() => _PlanScreenState();
}

class _PlanScreenState extends State<PlanScreen> {
  /// Latest plan row: {id, week_start, status, progress, error, targets, plan, fallback}.
  Map<String, dynamic>? _stored;
  List<Map<String, dynamic>> _family = [];
  int _pantryCount = 0;
  List<String> _pantryNames = [];
  bool _loading = true;
  bool _starting = false;
  String? _error;
  int _day = 0;
  int _member = 0;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _loadLatest();
    _loadFamily();
    _loadPantry();
  }

  @override
  void didUpdateWidget(PlanScreen old) {
    super.didUpdateWidget(old);
    if (old.familyVersion != widget.familyVersion) _loadFamily();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  // ---------- data ----------

  /// The plan to show: the latest one if it is finished, otherwise the last finished one
  /// (so the person keeps their old week while a new one is being built).
  Map<String, dynamic>? get _ready {
    final s = _stored;
    if (s == null) return null;
    if (s['status'] == 'ready') return s;
    final f = s['fallback'];
    return f is Map ? Map<String, dynamic>.from(f) : null;
  }

  bool get _building => _stored?['status'] == 'building';

  void _notifyPlan() {
    final id = _ready?['id'];
    widget.onPlanChanged?.call(id is num ? id.toInt() : null);
  }

  List<Map<String, dynamic>> _membersOf(Map<String, dynamic> stored) {
    final plan = stored['plan'];
    if (plan is! Map || plan['members'] is! List) return [];
    return (plan['members'] as List).map((m) => Map<String, dynamic>.from(m as Map)).toList();
  }

  /// Opens on today when today falls inside the plan's week, otherwise on day 1.
  int _startDay(Map<String, dynamic>? stored) {
    if (stored == null) return 0;
    final members = _membersOf(stored);
    final days = members.isNotEmpty && members.first['days'] is List
        ? (members.first['days'] as List).length
        : 0;
    return todayIndexIn(stored['week_start']?.toString(), days) ?? 0;
  }

  void _syncPolling() {
    if (_building && _timer == null) {
      _timer = Timer.periodic(const Duration(seconds: 4), (_) => _loadLatest(quiet: true));
    } else if (!_building && _timer != null) {
      _timer!.cancel();
      _timer = null;
    }
  }

  Future<void> _signOut() async {
    await Api.instance.logout();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const AuthScreen()),
      (_) => false,
    );
  }

  Future<void> _loadLatest({bool quiet = false}) async {
    try {
      final latest = await Api.instance.latestPlan();
      if (!mounted) return;
      final wasBuilding = _building;
      setState(() {
        _stored = latest;
        if (!quiet || (wasBuilding && !_building)) {
          _day = _startDay(_ready);
          _member = 0;
        }
      });
      _notifyPlan();
      _syncPolling();
    } on ApiException catch (e) {
      if (e.status == 401) return _signOut();
      if (!quiet && mounted) setState(() => _error = e.message);
    } finally {
      if (mounted && _loading) setState(() => _loading = false);
    }
  }

  Future<void> _loadFamily() async {
    try {
      final family = await Api.instance.household();
      if (mounted) setState(() => _family = family);
    } on ApiException {
      // The family list simply stays as it was.
    }
  }

  Future<void> _loadPantry() async {
    try {
      final data = await Api.instance.pantry();
      final items = data['items'] as List;
      if (mounted) {
        setState(() {
          _pantryCount = items.length;
          _pantryNames = [for (final i in items) (i as Map)['name'].toString()];
        });
      }
    } on ApiException {
      // The card simply keeps its default wording.
    }
  }

  /// Opens "What do you have at home?". It can ask for the week to be built straight away.
  /// With [thenConfirm], a saved list takes the person back to the build confirmation.
  Future<void> _openPantry({bool thenConfirm = false}) async {
    final result = await Navigator.of(context)
        .push<String>(MaterialPageRoute(builder: (_) => const PantryScreen()));
    await _loadPantry();
    if (!mounted) return;
    if (result == 'build') {
      if (!_building && !_starting) _start();
    } else if (thenConfirm && result == 'saved') {
      _askBuild();
    }
  }

  /// Before any week is built: shows what is saved at home and offers photos, so the person
  /// confirms what the menu will be built from.
  Future<void> _askBuild() async {
    if (_building || _starting) return;
    await _loadPantry(); // the list may have gone out of date since it was saved
    if (!mounted) return;
    final choice = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      builder: (_) => _BuildSheet(names: _pantryNames),
    );
    if (!mounted || choice == null) return;
    if (choice == 'build') {
      _start();
    } else if (choice == 'photos') {
      await _openPantry(thenConfirm: true);
    }
  }

  Widget _pantryCard() {
    return AppCard(
      onTap: _openPantry,
      child: Row(
        children: [
          const Icon(Icons.kitchen_outlined, size: 32, color: AppColors.accent),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('What do you have at home?',
                    style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 2),
                Text(
                  _pantryCount > 0
                      ? '$_pantryCount ${_pantryCount == 1 ? 'food' : 'foods'} saved. Tap to check or add more.'
                      : 'Take a photo of your fridge or cupboard, and your first days will use what you already have.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ],
            ),
          ),
          const Icon(Icons.chevron_right, size: 28, color: AppColors.muted),
        ],
      ),
    );
  }

  Future<void> _start() async {
    setState(() {
      _starting = true;
      _error = null;
    });
    try {
      final previous = _ready;
      final plan = await Api.instance.startPlan();
      if (!mounted) return;
      setState(() {
        _stored = {...plan, 'fallback': plan['status'] == 'ready' ? null : previous};
      });
      _notifyPlan();
      _syncPolling();
    } on ApiException catch (e) {
      if (e.status == 401) return _signOut();
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _starting = false);
    }
  }

  /// Opens the print screen with the week's menu (it can also save the menu as a PDF).
  Future<void> _printMenu() async {
    final ready = _ready;
    if (ready == null) return;
    try {
      await printMenu(ready);
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('We could not prepare the menu for printing. Please try again.')));
    }
  }

  // ---------- build ----------

  @override
  Widget build(BuildContext context) {
    final ready = _ready;
    final canPrint = ready != null && _membersOf(ready).isNotEmpty;
    return Scaffold(
      appBar: appBar('My meals', actions: [
        if (canPrint)
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: TextButton.icon(
              onPressed: _printMenu,
              icon: const Icon(Icons.print_outlined, size: 26),
              label: const Text('Print'),
            ),
          ),
      ]),
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
                children: _content(),
              ),
      ),
    );
  }

  Widget _disclaimer() {
    return Text(
      'NutriPlan gives general nutrition guidance, not medical advice. '
      'If you have a medical condition, please speak to your doctor or a registered dietitian.',
      style: Theme.of(context).textTheme.bodySmall,
    );
  }

  List<Widget> _content() {
    final ready = _ready;
    final failed = _stored?['status'] == 'failed';
    final failure = failed && _stored?['error'] is Map
        ? (_stored!['error'] as Map)['message']?.toString()
        : null;

    return [
      if (_error != null) ...[
        Banner2(_error!, isError: true),
        const SizedBox(height: 16),
      ],
      if (_building) ...[
        _progressCard(),
        const SizedBox(height: 16),
      ],
      if (failed) ...[
        Banner2(failure ?? 'We could not build your plan this time. Please try again.',
            isError: true),
        const SizedBox(height: 12),
        FilledButton(
          onPressed: _starting ? null : _start,
          child: _starting ? const ButtonSpinner() : const Text('Try again'),
        ),
        const SizedBox(height: 20),
      ],
      if (ready == null) ...[
        Text('Let\'s build your week',
            style: Theme.of(context).textTheme.headlineSmall),
        const SizedBox(height: 8),
        Text(
          'We will plan every meal for the week and make a shopping list to match.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 20),
        if (widget.targets != null && widget.targets?['error'] == null)
          _targetsCard(widget.targets!)
        else if (widget.targets?['error'] != null)
          Banner2(widget.targets!['error'].toString(), isError: true),
        const SizedBox(height: 16),
        _pantryCard(),
        const SizedBox(height: 12),
        if (!_building && !failed)
          FilledButton(
            onPressed: _starting ? null : _askBuild,
            child: _starting ? const ButtonSpinner() : const Text('Build my week'),
          ),
        if (widget.onOpenFamily != null) ...[
          const SizedBox(height: 12),
          OutlinedButton(
            onPressed: widget.onOpenFamily,
            child: const Text('Cooking for others? Add family'),
          ),
        ],
        const SizedBox(height: 28),
        _disclaimer(),
      ] else
        ..._planView(ready),
    ];
  }

  Widget _progressCard() {
    final msg = _stored?['progress']?.toString();
    return AppCard(
      padding: const EdgeInsets.all(20),
      child: Row(
        children: [
          const SizedBox(
            height: 32,
            width: 32,
            child: CircularProgressIndicator(strokeWidth: 3.5, color: AppColors.accent),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  msg == null || msg.isEmpty ? 'Building your week' : msg,
                  style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 4),
                const Text(
                  'This can take a few minutes while we check every meal against your calories, allergies and vitamins. You can leave this screen.',
                  style: TextStyle(fontSize: 16, height: 1.4, color: AppColors.muted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stat(String value, String label) {
    return Expanded(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(value, style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(fontSize: 16, color: AppColors.muted)),
        ],
      ),
    );
  }

  /// The daily target figures, without a card around them.
  Widget _targetsBody(Map<String, dynamic> t) {
    final drinks =
        t['drinks'] is Map ? Map<String, dynamic>.from(t['drinks'] as Map) : null;
    final drinkKcal = (toDouble(drinks?['kcal']) ?? 0).round();
    // With usual drinks, the macro figures show what the meals themselves should provide.
    final m = (drinkKcal > 0 && t['food'] is Map)
        ? Map<String, dynamic>.from(t['food'] as Map)
        : t;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Daily target', style: TextStyle(fontSize: 16, color: AppColors.muted)),
        const SizedBox(height: 4),
        Text('${fmtNum(t['kcal'])} kcal', style: Theme.of(context).textTheme.headlineSmall),
        const SizedBox(height: 14),
        Row(
          children: [
            _stat('${fmtNum(m['protein_g'])} g', 'Protein'),
            _stat('${fmtNum(m['carb_g'])} g', 'Carbs'),
            _stat('${fmtNum(m['fat_g'])} g', 'Fat'),
            _stat('${fmtNum(t['fibre_g'])} g', 'Fibre'),
          ],
        ),
        if (drinkKcal > 0) ...[
          const SizedBox(height: 14),
          Text(
              'Your usual drinks add about $drinkKcal kcal a day, so your meals are planned for ${fmtNum(m['kcal'])} kcal.',
              style: Theme.of(context).textTheme.bodySmall),
        ],
        if (t['weeks_to_goal'] != null) ...[
          const SizedBox(height: 8),
          Text(
              'It should take about ${t['weeks_to_goal']} weeks to reach your goal weight at the pace you chose.',
              style: Theme.of(context).textTheme.bodySmall),
        ],
      ],
    );
  }

  Widget _targetsCard(Map<String, dynamic> t) => AppCard(child: _targetsBody(t));

  List<Widget> _planView(Map<String, dynamic> stored) {
    final members = _membersOf(stored);
    if (members.isEmpty) {
      return [
        const Banner2(
            'This plan was made with an older version of NutriPlan. Please build a new week.',
            isError: true),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _starting || _building ? null : _askBuild,
          child: const Text('Build a new week'),
        ),
      ];
    }
    var mi = _member;
    if (mi >= members.length) mi = members.length - 1;
    if (mi < 0) mi = 0;
    final member = members[mi];
    final isChild = member['kind'] == 'child';
    final days = (member['days'] as List)
        .map((d) => Map<String, dynamic>.from(d as Map))
        .toList();
    if (days.isEmpty) {
      return [
        const Banner2('This plan has no days. Please build a new week.', isError: true),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _starting || _building ? null : _askBuild,
          child: const Text('Build a new week'),
        ),
      ];
    }
    var di = _day;
    if (di >= days.length) di = days.length - 1;
    if (di < 0) di = 0;

    final weekStart = stored['week_start']?.toString();
    final todayIdx = todayIndexIn(weekStart, days.length);
    final date = planDate(weekStart, di);
    final dayName = di == todayIdx
        ? 'Today'
        : (date != null ? dayLabel(date) : 'Day ${di + 1}');
    final heading = members.length > 1 ? '$dayName, ${member['name']}' : dayName;

    final day = days[di];
    final totals = Map<String, dynamic>.from(day['totals'] as Map);
    final meals = (day['meals'] as List)
        .map((m) => Map<String, dynamic>.from(m as Map))
        .toList();
    final planWarnings =
        (stored['plan'] is Map ? (stored['plan'] as Map)['warnings'] : null) as List? ??
            const [];
    final warnings = [...planWarnings, ...((member['warnings'] as List?) ?? const [])];
    final micros = member['micros'] is Map
        ? Map<String, dynamic>.from(member['micros'] as Map)
        : <String, dynamic>{};
    final targets = member['targets'] is Map
        ? Map<String, dynamic>.from(member['targets'] as Map)
        : null;

    // Has the family changed since this week was built?
    final planIds = members.map((m) => (m['member_id'] as num).toInt()).toSet();
    final familyIds = _family.map((m) => (m['id'] as num).toInt()).toSet();
    final familyChanged = _family.isNotEmpty &&
        (planIds.length != familyIds.length || !planIds.containsAll(familyIds));

    final hasDetails = targets != null || micros.isNotEmpty || warnings.isNotEmpty;

    return [
      if (familyChanged) ...[
        const Banner2(
            'Your family has changed since this week was built. Build a new week to include the changes.'),
        const SizedBox(height: 16),
      ],
      if (members.length > 1) ...[
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (var i = 0; i < members.length; i++) ...[
                if (i > 0) const SizedBox(width: 8),
                _memberPill(
                    members[i]['name'].toString(), i == mi, () => setState(() => _member = i)),
              ],
            ],
          ),
        ),
        const SizedBox(height: 16),
      ],
      Row(
        children: [
          for (var i = 0; i < days.length; i++) ...[
            if (i > 0) const SizedBox(width: 6),
            Expanded(child: _dayPill(i, di, weekStart, todayIdx)),
          ],
        ],
      ),
      const SizedBox(height: 20),
      Text(heading, style: Theme.of(context).textTheme.headlineSmall),
      const SizedBox(height: 12),
      if (isChild) ...[
        Banner2(
            'Children eat the shared family dinners. Breakfast, lunch and snacks are not planned for ${member['name']}.'),
        const SizedBox(height: 12),
      ],
      AppCard(
        child: Row(
          children: [
            _stat(fmtNum(totals['kcal']), isChild ? 'kcal at dinner' : 'kcal'),
            _stat('${fmtNum(totals['protein_g'])} g', 'Protein'),
            _stat('${fmtNum(totals['carb_g'])} g', 'Carbs'),
            _stat('${fmtNum(totals['fat_g'])} g', 'Fat'),
          ],
        ),
      ),
      const SizedBox(height: 12),
      for (final meal in meals) ...[
        _MealCard(meal: meal),
        const SizedBox(height: 12),
      ],
      const SizedBox(height: 4),
      _pantryCard(),
      const SizedBox(height: 12),
      if (_building)
        _progressCard()
      else
        OutlinedButton(
          onPressed: _starting ? null : _askBuild,
          child: _starting
              ? const SizedBox(
                  height: 26,
                  width: 26,
                  child: CircularProgressIndicator(strokeWidth: 3, color: AppColors.accent))
              : const Text('Build a new week'),
        ),
      if (hasDetails) ...[
        const SizedBox(height: 20),
        _Collapsible(
          title: 'Nutrition details',
          subtitle: 'Daily target, vitamins and notes',
          children: [
            if (targets != null) ...[
              _targetsBody(targets),
              const SizedBox(height: 20),
            ],
            if (micros.isNotEmpty) ...[
              Text('Vitamins and minerals', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 2),
              Text('Weekly average compared with the daily goal',
                  style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 12),
              for (final entry in micros.values)
                if (entry is Map) _microRow(Map<String, dynamic>.from(entry)),
              const SizedBox(height: 8),
            ],
            if (warnings.isNotEmpty) ...[
              Text(warnings.length == 1 ? 'A note about this plan' : 'Notes about this plan',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              for (final w in warnings)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Text(w.toString(), style: const TextStyle(fontSize: 17, height: 1.4)),
                ),
            ],
          ],
        ),
      ],
      const SizedBox(height: 28),
      _disclaimer(),
    ];
  }

  Widget _memberPill(String name, bool selected, VoidCallback onTap) {
    return Material(
      color: selected ? AppColors.accent : AppColors.surface,
      borderRadius: BorderRadius.circular(28),
      child: InkWell(
        borderRadius: BorderRadius.circular(28),
        onTap: onTap,
        child: Container(
          constraints: const BoxConstraints(minHeight: 52),
          alignment: Alignment.center,
          padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: selected ? AppColors.accent : AppColors.line, width: 1.5),
          ),
          child: Text(
            name,
            style: TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w700,
              color: selected ? AppColors.onAccent : AppColors.text,
            ),
          ),
        ),
      ),
    );
  }

  Widget _dayPill(int i, int selected, String? weekStart, int? todayIdx) {
    final isSelected = i == selected;
    final date = planDate(weekStart, i);
    final top = date != null ? weekdayShort(date) : 'Day';
    final bottom = date != null ? '${date.day}' : '${i + 1}';
    final fg = isSelected ? AppColors.onAccent : AppColors.text;
    return Material(
      color: isSelected ? AppColors.accent : AppColors.surface,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => setState(() => _day = i),
        child: Container(
          height: 72,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: isSelected
                  ? AppColors.accent
                  : (i == todayIdx ? AppColors.accent : AppColors.line),
              width: i == todayIdx && !isSelected ? 2.5 : 1.5,
            ),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(top, style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: fg)),
              const SizedBox(height: 2),
              Text(bottom,
                  style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800, color: fg)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _microRow(Map<String, dynamic> m) {
    final pct = (toDouble(m['pct']) ?? 0);
    final color = pct >= 90
        ? AppColors.good
        : (pct >= 60 ? AppColors.warn : AppColors.error);
    var value = pct / 100;
    if (value > 1) value = 1;
    if (value < 0) value = 0;
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Row(
        children: [
          SizedBox(
              width: 130,
              child: Text(m['label'].toString(), style: const TextStyle(fontSize: 17))),
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(5),
              child: LinearProgressIndicator(
                value: value,
                minHeight: 10,
                backgroundColor: AppColors.surfaceHigh,
                color: color,
              ),
            ),
          ),
          SizedBox(
            width: 60,
            child: Text('${pct.round()}%',
                textAlign: TextAlign.right,
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}

/// A meal: name and calories at a glance, tap for ingredients and how to make it.
class _MealCard extends StatefulWidget {
  const _MealCard({required this.meal});

  final Map<String, dynamic> meal;

  @override
  State<_MealCard> createState() => _MealCardState();
}

class _MealCardState extends State<_MealCard> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final meal = widget.meal;
    final items = (meal['items'] as List)
        .map((i) => Map<String, dynamic>.from(i as Map))
        .toList();
    final prep = (meal['prep'] ?? '').toString();
    final shared = meal['shared'] == true;
    final slot = capitalise(meal['slot'].toString());
    return AppCard(
      onTap: () => setState(() => _open = !_open),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(shared ? '$slot for the whole family' : slot,
                        style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w600,
                            color: shared ? AppColors.accent : AppColors.muted)),
                    const SizedBox(height: 2),
                    Text(meal['name'].toString(),
                        style: Theme.of(context).textTheme.titleMedium),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(fmtNum(meal['kcal']),
                      style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
                  const Text('kcal', style: TextStyle(fontSize: 15, color: AppColors.muted)),
                ],
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Text(_open ? 'Hide ingredients' : 'Show ingredients',
                  style: const TextStyle(
                      fontSize: 17, fontWeight: FontWeight.w700, color: AppColors.accent)),
              const SizedBox(width: 4),
              Icon(_open ? Icons.expand_less : Icons.expand_more,
                  size: 28, color: AppColors.accent),
            ],
          ),
          if (_open) ...[
            const SizedBox(height: 12),
            const Divider(height: 1, color: AppColors.line),
            const SizedBox(height: 12),
            for (final item in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  children: [
                    Expanded(
                        child: Text(item['name'].toString(),
                            style: const TextStyle(fontSize: 18))),
                    if (item['at_home'] == true)
                      const Padding(
                        padding: EdgeInsets.only(right: 10),
                        child: Text('At home',
                            style: TextStyle(
                                fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.good)),
                      ),
                    Text('${fmtNum(item['amount'])} ${item['unit']}',
                        style: const TextStyle(fontSize: 18, color: AppColors.muted)),
                  ],
                ),
              ),
            const SizedBox(height: 4),
            Text(
              'Protein ${fmtNum(meal['protein_g'])} g   Carbs ${fmtNum(meal['carb_g'])} g   Fat ${fmtNum(meal['fat_g'])} g',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (prep.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(prep, style: const TextStyle(fontSize: 18, height: 1.5)),
            ],
          ],
        ],
      ),
    );
  }
}

/// A closed-by-default section for details most people do not need every day.
class _Collapsible extends StatefulWidget {
  const _Collapsible({required this.title, required this.subtitle, required this.children});

  final String title;
  final String subtitle;
  final List<Widget> children;

  @override
  State<_Collapsible> createState() => _CollapsibleState();
}

class _CollapsibleState extends State<_Collapsible> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      onTap: () => setState(() => _open = !_open),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.title, style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(widget.subtitle, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Text(_open ? 'Hide' : 'Show',
                  style: const TextStyle(
                      fontSize: 17, fontWeight: FontWeight.w700, color: AppColors.accent)),
              Icon(_open ? Icons.expand_less : Icons.expand_more,
                  size: 28, color: AppColors.accent),
            ],
          ),
          if (_open) ...[
            const SizedBox(height: 16),
            ...widget.children,
          ],
        ],
      ),
    );
  }
}

/// The last question before a week is built: here is what we will use from home, is that right?
class _BuildSheet extends StatelessWidget {
  const _BuildSheet({required this.names});

  final List<String> names;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final have = names.isNotEmpty;
    final shown = names.take(8).join(', ');
    final more = names.length > 8 ? ' and ${names.length - 8} more' : '';
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(have ? 'Build your week now?' : 'Anything at home to use up?',
                style: theme.textTheme.headlineSmall),
            const SizedBox(height: 10),
            Text(
              have
                  ? 'You have ${names.length} ${names.length == 1 ? 'food' : 'foods'} saved at home: $shown$more. Your first days will be planned around them, and they will come off your shopping list.'
                  : 'Take photos of your fridge, freezer and cupboard, and your first days will be planned around what you already have. It also makes your shopping list shorter.',
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 20),
            if (have) ...[
              FilledButton(
                onPressed: () => Navigator.of(context).pop('build'),
                child: const Text('Yes, build my week'),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () => Navigator.of(context).pop('photos'),
                icon: const Icon(Icons.photo_camera_outlined, size: 26),
                label: const Text('Check or add photos'),
              ),
            ] else ...[
              FilledButton.icon(
                onPressed: () => Navigator.of(context).pop('photos'),
                icon: const Icon(Icons.photo_camera_outlined, size: 28),
                label: const Text('Take photos of what I have'),
              ),
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () => Navigator.of(context).pop('build'),
                child: const Text('Build without photos'),
              ),
            ],
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ),
    );
  }
}
