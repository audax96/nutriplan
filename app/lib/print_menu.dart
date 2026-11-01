import 'dart:typed_data';

import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import 'util.dart';

/// Rows of the printed menu, in the order they appear on the page.
const _slots = [
  ['breakfast', 'Breakfast'],
  ['lunch', 'Lunch'],
  ['dinner', 'Dinner'],
  ['snack', 'Snack'],
  ['drink', 'Drink'],
];

const _green = PdfColor.fromInt(0xFF1B6B4A);
const _lightGreen = PdfColor.fromInt(0xFFE3F0E8);

/// The built-in PDF font only knows basic Latin letters, so swap common punctuation for
/// plain versions and drop anything it cannot draw (instead of printing empty boxes).
String _clean(String s) {
  const swaps = {
    '–': '-', '—': '-', '‘': "'", '’': "'",
    '“': '"', '”': '"', '…': '...', ' ': ' ',
  };
  var out = s;
  swaps.forEach((from, to) => out = out.replaceAll(from, to));
  return String.fromCharCodes(out.runes.where((r) => r < 256));
}

/// Builds a landscape A4 menu for the fridge: one page per adult, days across the top,
/// meals down the side. Children eat the shared dinners, which every adult page shows.
/// Everything is made on the phone; nothing is sent anywhere.
Future<Uint8List> buildMenuPdf(Map<String, dynamic> stored) async {
  final plan = stored['plan'];
  final rawMembers = plan is Map && plan['members'] is List ? plan['members'] as List : const [];
  final adults = rawMembers
      .map((m) => Map<String, dynamic>.from(m as Map))
      .where((m) => m['kind'] != 'child')
      .toList();
  if (adults.isEmpty) {
    throw StateError('There is no menu to print.');
  }
  final showNames = rawMembers.length > 1;
  final weekStart = stored['week_start']?.toString();

  final doc = pw.Document(title: 'NutriPlan weekly menu');
  for (final member in adults) {
    final days = (member['days'] as List)
        .map((d) => Map<String, dynamic>.from(d as Map))
        .toList();
    if (days.isEmpty) continue;

    final first = planDate(weekStart, 0);
    final last = planDate(weekStart, days.length - 1);
    final range = (first != null && last != null) ? '${dayLabel(first)} to ${dayLabel(last)}' : '';
    final title = showNames ? 'Weekly menu for ${_clean(member['name'].toString())}' : 'Weekly menu';

    // Which meal names fall in which slot, per day.
    String cell(Map<String, dynamic> day, String slot) {
      final meals = (day['meals'] as List)
          .map((m) => Map<String, dynamic>.from(m as Map))
          .where((m) => m['slot'] == slot)
          .map((m) => _clean(m['name'].toString()))
          .toList();
      return meals.join('\n');
    }

    final slotRows = _slots.where((s) => days.any((d) => cell(d, s[0]).isNotEmpty)).toList();

    pw.Widget pad(pw.Widget child, {pw.Alignment alignment = pw.Alignment.topLeft}) =>
        pw.Container(
          padding: const pw.EdgeInsets.all(6),
          alignment: alignment,
          child: child,
        );

    final headerRow = pw.TableRow(
      repeat: true,
      decoration: const pw.BoxDecoration(color: _green),
      children: [
        pad(pw.Text('', style: const pw.TextStyle(fontSize: 12))),
        for (var i = 0; i < days.length; i++)
          pad(
            pw.Text(
              planDate(weekStart, i) != null
                  ? dayLabel(planDate(weekStart, i)!).replaceFirst(' ', '\n')
                  : 'Day ${i + 1}',
              textAlign: pw.TextAlign.center,
              style: pw.TextStyle(
                fontSize: 12,
                fontWeight: pw.FontWeight.bold,
                color: PdfColors.white,
              ),
            ),
            alignment: pw.Alignment.center,
          ),
      ],
    );

    final bodyRows = [
      for (final s in slotRows)
        pw.TableRow(
          decoration: s[0] == 'dinner' ? const pw.BoxDecoration(color: _lightGreen) : null,
          children: [
            pad(pw.Text(s[1],
                style: pw.TextStyle(fontSize: 12, fontWeight: pw.FontWeight.bold))),
            for (final d in days)
              pad(pw.Text(cell(d, s[0]), style: const pw.TextStyle(fontSize: 11))),
          ],
        ),
    ];

    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4.landscape,
        margin: const pw.EdgeInsets.all(28),
        footer: (ctx) => pw.Text(
          'Made with NutriPlan. General nutrition guidance, not medical advice.',
          style: const pw.TextStyle(fontSize: 9, color: PdfColors.grey700),
        ),
        build: (ctx) => [
          pw.Text(title, style: pw.TextStyle(fontSize: 26, fontWeight: pw.FontWeight.bold)),
          if (range.isNotEmpty) ...[
            pw.SizedBox(height: 4),
            pw.Text(range, style: const pw.TextStyle(fontSize: 14, color: PdfColors.grey800)),
          ],
          pw.SizedBox(height: 14),
          pw.Table(
            border: pw.TableBorder.all(color: PdfColors.grey600, width: 0.8),
            columnWidths: const {0: pw.FixedColumnWidth(74)},
            defaultColumnWidth: const pw.FlexColumnWidth(),
            children: [headerRow, ...bodyRows],
          ),
        ],
      ),
    );
  }
  return doc.save();
}

/// Opens the phone's print screen, which can also save the menu as a PDF or share it.
Future<void> printMenu(Map<String, dynamic> stored) async {
  final bytes = await buildMenuPdf(stored);
  await Printing.layoutPdf(
    name: 'NutriPlan weekly menu',
    onLayout: (_) async => bytes,
  );
}
