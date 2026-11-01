import 'package:flutter/material.dart';

/// One small palette, used everywhere: a warm white page, white cards and a deep green accent.
/// Text colours are chosen for strong contrast (all above WCAG AA on the surfaces they sit on).
class AppColors {
  static const bg = Color(0xFFF7F5F0);
  static const surface = Color(0xFFFFFFFF);
  static const surfaceHigh = Color(0xFFE3F0E8);
  static const line = Color(0xFFBDB7A8);
  static const text = Color(0xFF1C2024);
  static const muted = Color(0xFF555B63);
  static const accent = Color(0xFF1B6B4A);
  static const onAccent = Color(0xFFFFFFFF);
  static const good = Color(0xFF1E7A46);
  static const warn = Color(0xFF8A5300);
  static const error = Color(0xFFB3261E);
}

ThemeData buildAppTheme() {
  final base = ThemeData(brightness: Brightness.light, useMaterial3: true);
  final textTheme = base.textTheme
      .apply(bodyColor: AppColors.text, displayColor: AppColors.text)
      .copyWith(
        headlineMedium: const TextStyle(
            fontSize: 32, fontWeight: FontWeight.w700, color: AppColors.text, height: 1.2),
        headlineSmall: const TextStyle(
            fontSize: 28, fontWeight: FontWeight.w700, color: AppColors.text, height: 1.2),
        titleLarge: const TextStyle(
            fontSize: 22, fontWeight: FontWeight.w700, color: AppColors.text),
        titleMedium: const TextStyle(
            fontSize: 19, fontWeight: FontWeight.w600, color: AppColors.text),
        bodyLarge: const TextStyle(fontSize: 19, height: 1.45, color: AppColors.text),
        bodyMedium: const TextStyle(fontSize: 18, height: 1.45, color: AppColors.text),
        bodySmall: const TextStyle(fontSize: 16, height: 1.45, color: AppColors.muted),
        labelLarge: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
      );

  return ThemeData(
    brightness: Brightness.light,
    useMaterial3: true,
    scaffoldBackgroundColor: AppColors.bg,
    canvasColor: AppColors.surface,
    colorScheme: const ColorScheme.light(
      primary: AppColors.accent,
      onPrimary: AppColors.onAccent,
      secondary: AppColors.accent,
      onSecondary: AppColors.onAccent,
      surface: AppColors.surface,
      onSurface: AppColors.text,
      error: AppColors.error,
      onError: AppColors.onAccent,
    ),
    textTheme: textTheme,
    dividerColor: AppColors.line,
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: AppColors.accent,
        foregroundColor: AppColors.onAccent,
        disabledBackgroundColor: AppColors.surfaceHigh,
        disabledForegroundColor: AppColors.muted,
        minimumSize: const Size.fromHeight(60),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        textStyle: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: AppColors.accent,
        backgroundColor: AppColors.surface,
        side: const BorderSide(color: AppColors.accent, width: 1.5),
        minimumSize: const Size.fromHeight(56),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        foregroundColor: AppColors.accent,
        minimumSize: const Size(56, 52),
        textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
      ),
    ),
    snackBarTheme: const SnackBarThemeData(
      backgroundColor: AppColors.text,
      contentTextStyle: TextStyle(color: AppColors.surface, fontSize: 18),
      behavior: SnackBarBehavior.floating,
    ),
  );
}

/// Standard top bar: flat, same colour as the page, no shadow.
///
/// Pass [backLabel] (for example "Back" or "Cancel") on any screen that was opened from
/// another screen. It shows a large, clearly labelled button instead of a small arrow.
AppBar appBar(String title,
    {List<Widget>? actions, String? backLabel, VoidCallback? onBack}) {
  return AppBar(
    title: Text(title, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700)),
    backgroundColor: AppColors.bg,
    surfaceTintColor: AppColors.bg,
    elevation: 0,
    scrolledUnderElevation: 0,
    toolbarHeight: 64,
    automaticallyImplyLeading: false,
    leadingWidth: backLabel == null ? null : 128,
    leading: backLabel == null
        ? null
        : Builder(
            builder: (context) => Padding(
              padding: const EdgeInsets.only(left: 8),
              child: TextButton.icon(
                onPressed: onBack ?? () => Navigator.of(context).maybePop(),
                icon: Icon(backLabel == 'Cancel' ? Icons.close : Icons.arrow_back, size: 26),
                label: Text(backLabel),
              ),
            ),
          ),
    actions: actions,
  );
}

/// Standard text-field look: filled, large, with a clear accent outline when focused.
InputDecoration fieldDecoration(String label,
    {String? suffix,
    String? prefix,
    String? helper,
    String? error,
    String? hint,
    Widget? suffixIcon}) {
  OutlineInputBorder border(Color c, [double w = 1.5]) => OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: c, width: w),
      );
  return InputDecoration(
    labelText: label,
    hintText: hint,
    helperText: helper,
    helperMaxLines: 3,
    errorText: error,
    errorMaxLines: 3,
    suffixText: suffix,
    prefixText: prefix,
    suffixIcon: suffixIcon,
    filled: true,
    fillColor: AppColors.surface,
    labelStyle: const TextStyle(color: AppColors.muted, fontSize: 18),
    floatingLabelStyle: const TextStyle(color: AppColors.accent, fontSize: 18),
    hintStyle: const TextStyle(color: AppColors.muted, fontSize: 18),
    helperStyle: const TextStyle(color: AppColors.muted, fontSize: 16),
    errorStyle: const TextStyle(color: AppColors.error, fontSize: 16),
    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 20),
    enabledBorder: border(AppColors.line),
    border: border(AppColors.line),
    focusedBorder: border(AppColors.accent, 2.5),
    errorBorder: border(AppColors.error),
    focusedErrorBorder: border(AppColors.error, 2.5),
  );
}

/// The one card style. Tappable when onTap is given.
class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.all(18),
    this.color = AppColors.surface,
  });

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: color,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: AppColors.line.withAlpha(110)),
          ),
          padding: padding,
          child: child,
        ),
      ),
    );
  }
}

/// A big, clearly selectable option (used instead of dropdowns and tiny segmented buttons).
class ChoiceTile extends StatelessWidget {
  const ChoiceTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.subtitle,
  });

  final String label;
  final String? subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Material(
        color: selected ? AppColors.surfaceHigh : AppColors.surface,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: Container(
            constraints: const BoxConstraints(minHeight: 64),
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: selected ? AppColors.accent : AppColors.line,
                width: selected ? 2.5 : 1.5,
              ),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(label, style: theme.textTheme.titleMedium),
                      if (subtitle != null) ...[
                        const SizedBox(height: 2),
                        Text(subtitle!, style: theme.textTheme.bodySmall),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                if (selected)
                  const Icon(Icons.check_circle, color: AppColors.accent, size: 30)
                else
                  const Icon(Icons.circle_outlined, color: AppColors.line, size: 30),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A short coloured message box for errors and notices.
class Banner2 extends StatelessWidget {
  const Banner2(this.text, {super.key, this.isError = false});

  final String text;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final color = isError ? AppColors.error : AppColors.accent;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color, width: 1.5),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(isError ? Icons.error_outline : Icons.info_outline, color: color, size: 26),
          const SizedBox(width: 12),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 18, height: 1.4))),
        ],
      ),
    );
  }
}

/// Spinner sized for use inside a button.
class ButtonSpinner extends StatelessWidget {
  const ButtonSpinner({super.key});

  @override
  Widget build(BuildContext context) {
    return const SizedBox(
      height: 26,
      width: 26,
      child: CircularProgressIndicator(strokeWidth: 3, color: AppColors.onAccent),
    );
  }
}

/// Large question heading with an optional one-line helper underneath.
class StepHeading extends StatelessWidget {
  const StepHeading(this.title, {super.key, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: theme.textTheme.headlineSmall),
          if (subtitle != null) ...[
            const SizedBox(height: 8),
            Text(subtitle!, style: theme.textTheme.bodyMedium?.copyWith(color: AppColors.muted)),
          ],
        ],
      ),
    );
  }
}

/// A small heading used above a group of fields or options.
class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.hint});

  final String text;
  final String? hint;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(text, style: Theme.of(context).textTheme.titleMedium),
          if (hint != null) ...[
            const SizedBox(height: 2),
            Text(hint!, style: Theme.of(context).textTheme.bodySmall),
          ],
        ],
      ),
    );
  }
}

/// One destination in the bottom bar.
class BottomBarItem {
  const BottomBarItem(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

/// The main navigation: a few big buttons, each with an icon and a word, always visible.
/// Built from plain widgets so it looks the same on every Flutter version.
class AppBottomBar extends StatelessWidget {
  const AppBottomBar({
    super.key,
    required this.items,
    required this.selected,
    required this.onSelected,
  });

  final List<BottomBarItem> items;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      child: Container(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: AppColors.line, width: 1)),
        ),
        child: SafeArea(
          top: false,
          child: SizedBox(
            height: 76,
            child: Row(
              children: [
                for (var i = 0; i < items.length; i++)
                  Expanded(child: _tab(items[i], i == selected, () => onSelected(i))),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _tab(BottomBarItem item, bool isSelected, VoidCallback onTap) {
    final color = isSelected ? AppColors.accent : AppColors.muted;
    return Semantics(
      button: true,
      selected: isSelected,
      label: item.label,
      child: InkWell(
        onTap: onTap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 4),
              decoration: BoxDecoration(
                color: isSelected ? AppColors.surfaceHigh : Colors.transparent,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Icon(isSelected ? item.selectedIcon : item.icon, size: 28, color: color),
            ),
            const SizedBox(height: 2),
            Text(
              item.label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 16,
                fontWeight: isSelected ? FontWeight.w800 : FontWeight.w600,
                color: color,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
