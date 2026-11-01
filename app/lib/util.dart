/// Values from the PHP API can arrive as int, double or String (MySQL DECIMAL columns
/// are returned as strings), so everything numeric goes through these helpers.
double? toDouble(dynamic v) {
  if (v == null) return null;
  if (v is num) return v.toDouble();
  return double.tryParse(v.toString().trim().replaceAll(',', '.'));
}

/// "65.0" -> "65", "65.50" -> "65.5". Used to prefill text fields.
String fmtNum(dynamic v) {
  final d = toDouble(v);
  if (d == null) return '';
  return d == d.roundToDouble() ? d.toStringAsFixed(0) : d.toString();
}

String dateOnly(DateTime d) =>
    '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

const _monthShort = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
];

/// "12 Mar 1990"
String prettyDate(DateTime d) => '${d.day} ${_monthShort[d.month - 1]} ${d.year}';

const _weekdayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/// "Mon".."Sun" for a date.
String weekdayShort(DateTime d) => _weekdayShort[d.weekday - 1];

/// Which plan day (0-6) is today, or null when today is outside the plan's week.
int? todayIndexIn(String? weekStart, int dayCount) {
  final start = DateTime.tryParse(weekStart ?? '');
  if (start == null || dayCount <= 0) return null;
  final now = DateTime.now();
  final a = DateTime(start.year, start.month, start.day);
  final b = DateTime(now.year, now.month, now.day);
  final diff = b.difference(a).inDays;
  if (diff < 0 || diff >= dayCount) return null;
  return diff;
}

/// The calendar date of plan day [index], or null if the start date is unknown.
DateTime? planDate(String? weekStart, int index) {
  final start = DateTime.tryParse(weekStart ?? '');
  if (start == null) return null;
  return DateTime(start.year, start.month, start.day + index);
}

/// "Wed 14 Oct"
String dayLabel(DateTime d) => '${weekdayShort(d)} ${d.day} ${_monthShort[d.month - 1]}';

String capitalise(String s) => s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
