import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api.dart';
import '../ui.dart';

/// The Shopping tab: one list for the whole week, grouped by aisle.
class GroceryScreen extends StatefulWidget {
  const GroceryScreen({super.key, required this.planId});

  final int planId;

  @override
  State<GroceryScreen> createState() => _GroceryScreenState();
}

class _GroceryScreenState extends State<GroceryScreen> {
  late Future<Map<String, dynamic>> _future;
  final Set<String> _checked = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _future = Api.instance.grocery(widget.planId);
  }

  Future<void> _copy(String text) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('Shopping list copied')));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: appBar('Shopping list'),
      body: SafeArea(
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snap.hasError) {
              final err = snap.error;
              return ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  Banner2(
                      err is ApiException
                          ? err.message
                          : 'Something went wrong. Please try again.',
                      isError: true),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: () => setState(_load),
                    child: const Text('Try again'),
                  ),
                ],
              );
            }
            final data = snap.data!;
            final groups = (data['groups'] as List)
                .map((g) => Map<String, dynamic>.from(g as Map))
                .toList();
            // PHP sends an empty list as [], and older plans have no such key at all.
            final atHome = (data['at_home'] is List ? data['at_home'] as List : const [])
                .map((h) => Map<String, dynamic>.from(h as Map))
                .toList();

            return ListView(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
              children: [
                Text(
                    'This is the total for the whole week, for everyone you cook for. Tap an item to tick it off.',
                    style: theme.textTheme.bodySmall),
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: () => _copy(data['share_text'].toString()),
                  icon: const Icon(Icons.copy, size: 24),
                  label: const Text('Copy shopping list'),
                ),
                for (final group in groups) ...[
                  Padding(
                    padding: const EdgeInsets.only(top: 24, bottom: 10),
                    child: Text(group['aisle'].toString(), style: theme.textTheme.titleLarge),
                  ),
                  for (final raw in (group['items'] as List))
                    _item(Map<String, dynamic>.from(raw as Map), group['aisle'].toString()),
                ],
                if (atHome.isNotEmpty) ...[
                  Padding(
                    padding: const EdgeInsets.only(top: 28, bottom: 4),
                    child: Text('Already at home', style: theme.textTheme.titleLarge),
                  ),
                  Text(
                      'You have these already, and your first days use them. They are not on the list above.',
                      style: theme.textTheme.bodySmall),
                  const SizedBox(height: 10),
                  for (final h in atHome)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: AppCard(
                        color: AppColors.surfaceHigh,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                        child: Row(
                          children: [
                            const Icon(Icons.home_outlined, size: 28, color: AppColors.accent),
                            const SizedBox(width: 14),
                            Expanded(
                                child: Text(h['ingredient'].toString(),
                                    style: const TextStyle(fontSize: 19))),
                            Text(h['display'].toString(),
                                style: const TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.w600,
                                    color: AppColors.muted)),
                          ],
                        ),
                      ),
                    ),
                ],
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _item(Map<String, dynamic> item, String aisle) {
    final key = '$aisle/${item['ingredient_id']}';
    final done = _checked.contains(key);
    final textColor = done ? AppColors.muted : AppColors.text;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        onTap: () => setState(() {
          if (done) {
            _checked.remove(key);
          } else {
            _checked.add(key);
          }
        }),
        child: Row(
          children: [
            Icon(done ? Icons.check_circle : Icons.circle_outlined,
                size: 30, color: done ? AppColors.good : AppColors.muted),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                item['ingredient'].toString(),
                style: TextStyle(
                  fontSize: 19,
                  color: textColor,
                  decoration: done ? TextDecoration.lineThrough : TextDecoration.none,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(item['display'].toString(),
                style: const TextStyle(
                    fontSize: 18, fontWeight: FontWeight.w600, color: AppColors.muted)),
          ],
        ),
      ),
    );
  }
}
