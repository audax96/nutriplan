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
    '“': '"', '”': '"', '…': '...', ' ': ' ',
  };
  var out = s;
  swaps.forEach((from, to) => out = out.replaceAll(from, to));
  return String.fromCharCodes(out.runes.where((r) => r < 256));
}

List<Map<String, dynamic>> _maps(dynamic v) =>
    v is List ? v.map((e) => Map<String, dynamic>.from(e as Map)).toList() : const [];

/// "Oats (rolled) 60 g"
String _itemLine(Map<String, dynamic> item) =>
    _clean('${item['name']} ${fmtNum(item['amount'])} ${item['unit'] ?? 'g'}');

/// Builds a landscape A4 menu for the fridge: everyone's week one under the other (pages
/// break only where they have to), days across the top, meals down the side, and every
/// food with its weight. Children eat the shared dinners, so their section shows their
/// own dinner portions. Everything is made on the phone; nothing is sent anywhere.
///
/// With [compact], each meal's foods run on one wrapped line in smaller print. Used when a
/// very full meal makes a row taller than a page.
Future<Uint8List> buildMenuPdf(Map<String, dynamic> stored, {bool compact = false}) async {
  final plan = stored['plan'];
  final members = _maps(plan is Map ? plan['members'] : null)
      .where((m) => _maps(m['days']).isNotEmpty)
      .toList();
  if (members.isEmpty) {
    throw StateError('There is no menu to print.');
  }
  final showNames = members.length > 1;
  final weekStart = stored['week_start']?.toString();
  final dayCount = _maps(members.first['days']).length;
  final first = planDate(weekStart, 0);
  final last = planDate(weekStart, dayCount - 1);
  final range = (first != null && last != null) ? '${dayLabel(first)} to ${dayLabel(last)}' : '';

  pw.Widget pad(pw.Widget child, {pw.Alignment alignment = pw.Alignment.topLeft}) =>
      pw.Container(padding: const pw.EdgeInsets.all(5), alignment: alignment, child: child);

  /// One cell: each meal's name, then its foods with weights.
  pw.Widget cell(Map<String, dynamic> day, String slot) {
    final meals = _maps(day['meals']).where((m) => m['slot'] == slot).toList();
    return pad(pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < meals.length; i++) ...[
          if (i > 0) pw.SizedBox(height: 4),
          pw.Text(_clean(meals[i]['name'].toString()),
              style: pw.TextStyle(fontSize: compact ? 8 : 9.5, fontWeight: pw.FontWeight.bold)),
          pw.SizedBox(height: 2),
          if (compact)
            pw.Text(_maps(meals[i]['items']).map(_itemLine).join(', '),
                style: const pw.TextStyle(fontSize: 7, color: PdfColors.grey800))
          else
            for (final item in _maps(meals[i]['items']))
              pw.Text(_itemLine(item), style: const pw.TextStyle(fontSize: 8, color: PdfColors.grey800)),
        ],
      ],
    ));
  }

  /// One person's week as a table. The day header repeats when a table runs onto a new page.
  List<pw.Widget> section(Map<String, dynamic> member, bool firstSection) {
    final days = _maps(member['days']);
    final isChild = member['kind'] == 'child';
    final slotRows = _slots
        .where((s) => days.any((d) => _maps(d['meals']).any((m) => m['slot'] == s[0])))
        .toList();
    final name = _clean(member['name'].toString());
    final heading = isChild ? '$name (shared dinners, child portions)' : name;

    final headerRow = pw.TableRow(
      repeat: true,
      decoration: const pw.BoxDecoration(color: _green),
      children: [
        pad(pw.Text('')),
        for (var i = 0; i < days.length; i++)
          pad(
            pw.Text(
              planDate(weekStart, i) != null
                  ? dayLabel(planDate(weekStart, i)!).replaceFirst(' ', '\n')
                  : 'Day ${i + 1}',
              textAlign: pw.TextAlign.center,
              style: pw.TextStyle(fontSize: 10, fontWeight: pw.FontWeight.bold, color: PdfColors.white),
            ),
            alignment: pw.Alignment.center,
          ),
      ],
    );

    final totalsRow = pw.TableRow(
      children: [
        pad(pw.Text(isChild ? 'kcal' : 'Day total',
            style: pw.TextStyle(fontSize: 9, fontWeight: pw.FontWeight.bold))),
        for (final d in days)
          pad(
            pw.Text(
              d['totals'] is Map ? '${fmtNum((d['totals'] as Map)['kcal'])} kcal' : '',
              style: const pw.TextStyle(fontSize: 8.5, color: PdfColors.grey800),
            ),
          ),
      ],
    );

    return [
      if (showNames) ...[
        if (!firstSection) pw.SizedBox(height: 18),
        pw.Text(heading, style: pw.TextStyle(fontSize: 17, fontWeight: pw.FontWeight.bold, color: _green)),
        pw.SizedBox(height: 6),
      ],
      pw.Table(
        border: pw.TableBorder.all(color: PdfColors.grey600, width: 0.7),
        columnWidths: const {0: pw.FixedColumnWidth(62)},
        defaultColumnWidth: const pw.FlexColumnWidth(),
        children: [
          headerRow,
          for (final s in slotRows)
            pw.TableRow(
              decoration: s[0] == 'dinner' ? const pw.BoxDecoration(color: _lightGreen) : null,
              children: [
                pad(pw.Text(s[1], style: pw.TextStyle(fontSize: 10, fontWeight: pw.FontWeight.bold))),
                for (final d in days) cell(d, s[0]),
              ],
            ),
          totalsRow,
        ],
      ),
    ];
  }

  final doc = pw.Document(title: 'NutriPlan weekly menu');
  doc.addPage(
    pw.MultiPage(
      pageFormat: PdfPageFormat.a4.landscape,
      margin: const pw.EdgeInsets.all(24),
      footer: (ctx) => pw.Row(
        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
        children: [
          pw.Text('Made with NutriPlan. General nutrition guidance, not medical advice.',
              style: const pw.TextStyle(fontSize: 8, color: PdfColors.grey700)),
          pw.Text('Page ${ctx.pageNumber} of ${ctx.pagesCount}',
              style: const pw.TextStyle(fontSize: 8, color: PdfColors.grey700)),
        ],
      ),
      build: (ctx) => [
        pw.Text(showNames ? 'Weekly menu for the family' : 'Weekly menu',
            style: pw.TextStyle(fontSize: 22, fontWeight: pw.FontWeight.bold)),
        if (range.isNotEmpty) ...[
          pw.SizedBox(height: 3),
          pw.Text(range, style: const pw.TextStyle(fontSize: 12, color: PdfColors.grey800)),
        ],
        pw.SizedBox(height: 4),
        pw.Text('Weights are per person. Foods marked (raw) or (dry) are weighed before cooking.',
            style: const pw.TextStyle(fontSize: 9, color: PdfColors.grey700)),
        pw.SizedBox(height: 10),
        for (var i = 0; i < members.length; i++) ...section(members[i], i == 0),
      ],
    ),
  );
  return doc.save();
}

/// Opens the phone's print screen, which can also save the menu as a PDF or share it.
Future<void> printMenu(Map<String, dynamic> stored) async {
  Uint8List bytes;
  try {
    bytes = await buildMenuPdf(stored);
  } catch (_) {
    // Usually one very full meal that does not fit on a page: try the smaller layout.
    bytes = await buildMenuPdf(stored, compact: true);
  }
  await Printing.layoutPdf(
    name: 'NutriPlan weekly menu',
    onLayout: (_) async => bytes,
  );
}
