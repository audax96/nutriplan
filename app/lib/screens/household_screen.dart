import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import '../util.dart';
import 'child_screen.dart';
import 'onboarding_screen.dart';

/// The Family tab: everyone the account cooks for.
/// Adults get their own breakfast, lunch and snacks; everyone shares the same dinner.
class HouseholdScreen extends StatefulWidget {
  const HouseholdScreen({super.key, this.onChanged});

  /// Called after someone is added, edited or removed.
  final VoidCallback? onChanged;

  @override
  State<HouseholdScreen> createState() => _HouseholdScreenState();
}

class _HouseholdScreenState extends State<HouseholdScreen> {
  List<Map<String, dynamic>> _members = [];
  bool _loading = true;
  String? _error;

  static const int _maxMembers = 6;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final members = await Api.instance.household();
      if (!mounted) return;
      setState(() {
        _members = members;
        _error = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _reload() async {
    await _load();
    widget.onChanged?.call();
  }

  bool get _full => _members.length >= _maxMembers;

  Future<void> _addAdult() async {
    await Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => const OnboardingScreen(addingMember: true)));
    await _reload();
  }

  Future<void> _addChild() async {
    await Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => const ChildScreen()));
    await _reload();
  }

  Future<void> _edit(Map<String, dynamic> m) async {
    final id = (m['id'] as num).toInt();
    try {
      final detail = await Api.instance.getMember(id);
      if (!mounted) return;
      final profile = detail['profile'] is Map
          ? Map<String, dynamic>.from(detail['profile'] as Map)
          : <String, dynamic>{};
      if (m['kind'] == 'child') {
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => ChildScreen(memberId: id, existing: profile)));
      } else {
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => OnboardingScreen(existing: profile, memberId: id)));
      }
      await _reload();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _remove(Map<String, dynamic> m) async {
    final name = m['name'].toString();
    final yes = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text('Remove $name?'),
        content: Text(
            '$name\'s details will be deleted, and they will no longer appear in your meal plans.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep')),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: TextButton.styleFrom(foregroundColor: AppColors.error),
            child: const Text('Remove'),
          ),
        ],
      ),
    );
    if (yes != true) return;
    try {
      await Api.instance.deleteMember((m['id'] as num).toInt());
      await _reload();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  String _subtitle(Map<String, dynamic> m) {
    final age = m['age'];
    final kcal = toDouble(m['kcal']);
    if (m['kind'] == 'child') {
      return age == null ? 'Child' : 'Child, $age years old';
    }
    if (m['ready'] != true) return 'Details needed. Tap to add them.';
    final bits = <String>['Adult'];
    if (kcal != null) bits.add('${fmtNum(kcal)} kcal a day');
    return bits.join(', ');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: appBar('My family'),
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
                children: [
                  Text(
                    'Each adult gets their own breakfast, lunch and snacks. Everyone shares the same dinner, with portion sizes set for each person.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 16),
                  if (_error != null) ...[
                    Banner2(_error!, isError: true),
                    const SizedBox(height: 16),
                  ],
                  for (final m in _members) ...[
                    _memberCard(m),
                    const SizedBox(height: 12),
                  ],
                  const SizedBox(height: 12),
                  if (_full)
                    Text('A family can have up to $_maxMembers people.',
                        style: Theme.of(context).textTheme.bodySmall)
                  else ...[
                    FilledButton.icon(
                      onPressed: _addAdult,
                      icon: const Icon(Icons.person_add_alt_1, size: 26),
                      label: const Text('Add an adult'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: _addChild,
                      icon: const Icon(Icons.child_care, size: 26),
                      label: const Text('Add a child'),
                    ),
                  ],
                ],
              ),
      ),
    );
  }

  Widget _memberCard(Map<String, dynamic> m) {
    final isOwner = m['is_owner'] == true;
    final needsDetails = m['kind'] != 'child' && m['ready'] != true;
    return AppCard(
      onTap: () => _edit(m),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(m['kind'] == 'child' ? Icons.child_care : Icons.person_outline,
                  size: 30, color: AppColors.accent),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(isOwner ? '${m['name']} (you)' : m['name'].toString(),
                        style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(_subtitle(m),
                        style: TextStyle(
                            fontSize: 16,
                            color: needsDetails ? AppColors.warn : AppColors.muted)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              TextButton.icon(
                onPressed: () => _edit(m),
                icon: const Icon(Icons.edit_outlined, size: 24),
                label: const Text('Edit'),
              ),
              if (!isOwner) ...[
                const SizedBox(width: 8),
                TextButton.icon(
                  onPressed: () => _remove(m),
                  style: TextButton.styleFrom(foregroundColor: AppColors.error),
                  icon: const Icon(Icons.delete_outline, size: 24),
                  label: const Text('Remove'),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
