import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'auth_screen.dart';
import 'onboarding_screen.dart';

/// Account things that are not needed every day, kept in one place.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key, required this.profile});

  final Map<String, dynamic> profile;

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  String? _error;

  void _toSignIn() {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const AuthScreen()),
      (_) => false,
    );
  }

  Future<void> _signOut() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: const Text('Sign out?'),
        content: const Text('You will need your email and password to sign in again.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Stay signed in')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Sign out')),
        ],
      ),
    );
    if (yes != true) return;
    await Api.instance.logout();
    if (!mounted) return;
    _toSignIn();
  }

  Future<void> _deleteAccount() async {
    // The dialog owns its text field, so nothing is disposed while it is still animating out.
    final password = await showDialog<String>(
      context: context,
      builder: (_) => const _DeleteAccountDialog(),
    );
    if (password == null) return;
    if (password.isEmpty) {
      setState(() => _error = 'Please enter your password to delete your account.');
      return;
    }
    try {
      await Api.instance.deleteAccount(password);
      if (!mounted) return;
      _toSignIn();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  void _editDetails() {
    Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => OnboardingScreen(existing: widget.profile)));
  }

  Widget _row(IconData icon, String title, String subtitle, VoidCallback onTap,
      {Color color = AppColors.text}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: AppCard(
        onTap: onTap,
        child: Row(
          children: [
            Icon(icon, size: 30, color: color == AppColors.text ? AppColors.accent : color),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: Theme.of(context)
                          .textTheme
                          .titleMedium
                          ?.copyWith(color: color)),
                  const SizedBox(height: 2),
                  Text(subtitle, style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, size: 28, color: AppColors.muted),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: appBar('Settings'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
          children: [
            if (_error != null) ...[
              Banner2(_error!, isError: true),
              const SizedBox(height: 16),
            ],
            _row(Icons.person_outline, 'My details',
                'Change your weight, goal, food choices and allergies', _editDetails),
            _row(Icons.logout, 'Sign out', 'Leave your account on this phone', _signOut),
            const SizedBox(height: 16),
            Text(
              'NutriPlan gives general nutrition guidance, not medical advice. '
              'If you have a medical condition, please speak to your doctor or a registered dietitian.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 32),
            _row(Icons.delete_outline, 'Delete my account',
                'Permanently remove all of your details and plans', _deleteAccount,
                color: AppColors.error),
          ],
        ),
      ),
    );
  }
}

/// Asks for the password before the account is deleted (the server checks it again).
class _DeleteAccountDialog extends StatefulWidget {
  const _DeleteAccountDialog();

  @override
  State<_DeleteAccountDialog> createState() => _DeleteAccountDialogState();
}

class _DeleteAccountDialogState extends State<_DeleteAccountDialog> {
  final _password = TextEditingController();

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.surface,
      title: const Text('Delete your account?'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
                'This will permanently delete your details, your family\'s details, your meal plans and your shopping lists. This cannot be undone.'),
            const SizedBox(height: 16),
            TextField(
              controller: _password,
              obscureText: true,
              autofillHints: const [AutofillHints.password],
              style: const TextStyle(fontSize: 20),
              decoration: fieldDecoration('Your password'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Keep my account')),
        TextButton(
          onPressed: () => Navigator.pop(context, _password.text),
          style: TextButton.styleFrom(foregroundColor: AppColors.error),
          child: const Text('Delete everything'),
        ),
      ],
    );
  }
}
