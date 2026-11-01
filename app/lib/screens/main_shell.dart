import 'package:flutter/material.dart';

import '../ui.dart';
import 'grocery_screen.dart';
import 'household_screen.dart';
import 'plan_screen.dart';
import 'settings_screen.dart';

/// The home of the app: four big tabs along the bottom, always visible.
///
/// Meals - the week's plan.  Shopping - the shopping list.  Family - who you cook for.
/// Settings - your details, sign out and delete account.
class MainShell extends StatefulWidget {
  const MainShell({super.key, required this.profile, this.targets});

  final Map<String, dynamic> profile;
  final Map<String, dynamic>? targets;

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  static const _items = [
    BottomBarItem('Meals', Icons.restaurant_menu_outlined, Icons.restaurant_menu),
    BottomBarItem('Shopping', Icons.shopping_cart_outlined, Icons.shopping_cart),
    BottomBarItem('Family', Icons.group_outlined, Icons.group),
    BottomBarItem('Settings', Icons.settings_outlined, Icons.settings),
  ];

  int _tab = 0;

  /// The plan the shopping list belongs to (null until a week has been built).
  int? _planId;

  /// Goes up whenever the family changes, so the Meals tab can notice.
  int _familyVersion = 0;

  void _go(int i) => setState(() => _tab = i);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _tab,
        children: [
          PlanScreen(
            profile: widget.profile,
            targets: widget.targets,
            familyVersion: _familyVersion,
            onPlanChanged: (id) {
              if (id != _planId && mounted) setState(() => _planId = id);
            },
            onOpenFamily: () => _go(2),
          ),
          _planId == null
              ? _NoPlanYet(onBuild: () => _go(0))
              : GroceryScreen(key: ValueKey(_planId), planId: _planId!),
          HouseholdScreen(onChanged: () => setState(() => _familyVersion++)),
          SettingsScreen(profile: widget.profile),
        ],
      ),
      bottomNavigationBar: AppBottomBar(items: _items, selected: _tab, onSelected: _go),
    );
  }
}

/// Shown on the Shopping tab before a week has been built.
class _NoPlanYet extends StatelessWidget {
  const _NoPlanYet({required this.onBuild});

  final VoidCallback onBuild;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: appBar('Shopping list'),
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Icon(Icons.shopping_cart_outlined, size: 64, color: AppColors.muted),
                const SizedBox(height: 16),
                Text('No shopping list yet',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 8),
                Text(
                  'Your shopping list is made from your meals. Build your week first and the list will appear here.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 24),
                FilledButton(onPressed: onBuild, child: const Text('Go to Meals')),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
