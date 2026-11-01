import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'auth_screen.dart';
import 'main_shell.dart';
import 'onboarding_screen.dart';

/// Loads the profile and routes: no profile yet -> setup, otherwise -> the main tabs.
class HomeGate extends StatefulWidget {
  const HomeGate({super.key});

  @override
  State<HomeGate> createState() => _HomeGateState();
}

class _HomeGateState extends State<HomeGate> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = Api.instance.getProfile();
  }

  void _retry() => setState(() => _future = Api.instance.getProfile());

  Future<void> _signOut() async {
    await Api.instance.logout();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const AuthScreen()),
      (_) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (snap.hasError) {
          final err = snap.error;
          if (err is ApiException && err.status == 401) {
            // Session expired: go back to sign-in.
            WidgetsBinding.instance.addPostFrameCallback((_) => _signOut());
            return const Scaffold(body: Center(child: CircularProgressIndicator()));
          }
          return Scaffold(
            body: SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Banner2(
                          err is ApiException
                              ? err.message
                              : 'Something went wrong. Please try again.',
                          isError: true),
                      const SizedBox(height: 16),
                      FilledButton(onPressed: _retry, child: const Text('Try again')),
                      const SizedBox(height: 8),
                      TextButton(onPressed: _signOut, child: const Text('Sign out')),
                    ],
                  ),
                ),
              ),
            ),
          );
        }
        final data = snap.data!;
        final profile = data['profile'];
        if (profile == null) {
          return const OnboardingScreen();
        }
        return MainShell(
          profile: Map<String, dynamic>.from(profile as Map),
          targets: data['targets'] is Map
              ? Map<String, dynamic>.from(data['targets'] as Map)
              : null,
        );
      },
    );
  }
}
