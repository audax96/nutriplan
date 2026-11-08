import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'forgot_password_screen.dart';
import 'home_gate.dart';

class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key});

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _isLogin = false;
  bool _busy = false;
  bool _showPassword = false;
  String? _error;
  String? _info;

  /// Set when two-step sign-in asked for an emailed code.
  String? _challenge;
  String _hint = '';

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  void _toHome() {
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const HomeGate()),
    );
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
      _info = null;
    });
    try {
      final email = _email.text.trim();
      final password = _password.text;
      if (_isLogin) {
        final step = await Api.instance.login(email, password);
        if (!mounted) return;
        if (step != null) {
          // Two-step sign-in: the code is on its way to their inbox.
          if (step['challenge'] == null) {
            setState(() => _error = 'We could not start the sign-in code step. Please try again.');
            return;
          }
          setState(() {
            _challenge = step['challenge']?.toString();
            _hint = (step['email_hint'] ?? 'your email').toString();
            _code.clear();
          });
          return;
        }
      } else {
        await Api.instance.register(email, password);
      }
      if (!mounted) return;
      _toHome();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    final challenge = _challenge;
    if (challenge == null) return;
    setState(() {
      _busy = true;
      _error = null;
      _info = null;
    });
    try {
      await Api.instance.verifyLogin(challenge, _code.text.trim());
      if (!mounted) return;
      _toHome();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        // An expired sign-in needs the password again.
        if (e.code == 'challenge_expired') _challenge = null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    final challenge = _challenge;
    if (challenge == null) return;
    setState(() {
      _busy = true;
      _error = null;
      _info = null;
    });
    try {
      await Api.instance.resendLoginCode(challenge);
      if (mounted) setState(() => _info = 'We sent a new code to $_hint.');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        if (e.code == 'challenge_expired') _challenge = null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _forgot() async {
    final email = await Navigator.of(context).push<String>(MaterialPageRoute(
        builder: (_) => ForgotPasswordScreen(email: _email.text.trim())));
    if (email == null || !mounted) return;
    setState(() {
      _isLogin = true;
      _email.text = email;
      _password.clear();
      _error = null;
      _info = 'Your password has been changed. Please sign in with the new one.';
    });
  }

  Widget _messages() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_info != null) ...[
          const SizedBox(height: 16),
          Banner2(_info!),
        ],
        if (_error != null) ...[
          const SizedBox(height: 16),
          Banner2(_error!, isError: true),
        ],
      ],
    );
  }

  List<Widget> _codeStep(ThemeData theme) {
    return [
      Text('Check your email', style: theme.textTheme.headlineMedium?.copyWith(color: AppColors.accent)),
      const SizedBox(height: 8),
      Text(
        'We sent a 6-digit sign-in code to $_hint. It can take a minute to arrive; check your spam folder too.',
        style: theme.textTheme.bodyLarge?.copyWith(color: AppColors.muted),
      ),
      const SizedBox(height: 24),
      TextField(
        controller: _code,
        keyboardType: TextInputType.number,
        autofillHints: const [AutofillHints.oneTimeCode],
        maxLength: 6,
        autofocus: true,
        style: const TextStyle(fontSize: 28, letterSpacing: 8),
        decoration: fieldDecoration('Code'),
        onSubmitted: (_) => _busy ? null : _verify(),
      ),
      _messages(),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: _busy ? null : _verify,
        child: _busy ? const ButtonSpinner() : const Text('Sign in'),
      ),
      const SizedBox(height: 12),
      OutlinedButton(
        onPressed: _busy ? null : _resend,
        child: const Text('Send a new code'),
      ),
      const SizedBox(height: 8),
      TextButton(
        onPressed: _busy
            ? null
            : () => setState(() {
                  _challenge = null;
                  _error = null;
                  _info = null;
                }),
        child: const Text('Back'),
      ),
    ];
  }

  List<Widget> _passwordStep(ThemeData theme) {
    return [
      Text('NutriPlan', style: theme.textTheme.headlineMedium?.copyWith(color: AppColors.accent)),
      const SizedBox(height: 8),
      Text(
        _isLogin
            ? 'Welcome back. Please sign in to see your meals.'
            : 'Weekly meal plans built around your goals, allergies and tastes.',
        style: theme.textTheme.bodyLarge?.copyWith(color: AppColors.muted),
      ),
      const SizedBox(height: 32),
      TextField(
        controller: _email,
        keyboardType: TextInputType.emailAddress,
        autofillHints: const [AutofillHints.email],
        style: const TextStyle(fontSize: 20),
        decoration: fieldDecoration('Email address'),
      ),
      const SizedBox(height: 16),
      TextField(
        controller: _password,
        obscureText: !_showPassword,
        autofillHints: [_isLogin ? AutofillHints.password : AutofillHints.newPassword],
        style: const TextStyle(fontSize: 20),
        decoration: fieldDecoration(
          'Password',
          helper: _isLogin ? null : 'Use at least 8 characters.',
          suffixIcon: IconButton(
            tooltip: _showPassword ? 'Hide password' : 'Show password',
            iconSize: 28,
            onPressed: () => setState(() => _showPassword = !_showPassword),
            icon: Icon(_showPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined),
          ),
        ),
        onSubmitted: (_) => _busy ? null : _submit(),
      ),
      if (_isLogin)
        Align(
          alignment: Alignment.centerRight,
          child: TextButton(
            onPressed: _busy ? null : _forgot,
            child: const Text('Forgot password?'),
          ),
        ),
      _messages(),
      const SizedBox(height: 24),
      FilledButton(
        onPressed: _busy ? null : _submit,
        child: _busy ? const ButtonSpinner() : Text(_isLogin ? 'Sign in' : 'Create account'),
      ),
      const SizedBox(height: 12),
      OutlinedButton(
        onPressed: _busy
            ? null
            : () => setState(() {
                  _isLogin = !_isLogin;
                  _error = null;
                  _info = null;
                }),
        child: Text(_isLogin ? 'New here? Create an account' : 'Already have an account? Sign in'),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: _challenge != null ? _codeStep(theme) : _passwordStep(theme),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
