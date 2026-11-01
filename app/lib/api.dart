import 'dart:convert';

import 'package:flutter/foundation.dart' show kReleaseMode;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  ApiException(this.status, this.code, this.message, [this.extra = const {}]);

  final int status;
  final String code;
  final String message;
  final Map<String, dynamic> extra;

  @override
  String toString() => message;
}

/// Talks to the PHP backend. The Claude API key never lives in the app; the app only
/// ever sees the finished, verified plan.
class Api {
  Api._();
  static final Api instance = Api._();

  /// The Android emulator reaches your computer at 10.0.2.2.
  /// Override with: flutter run --dart-define=API_URL=https://your-server
  static const String baseUrl =
      String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000');

  /// A release build sends passwords and health data, so it must use HTTPS.
  /// Plain http:// is only accepted in debug and profile builds (testing on your own network).
  static Uri _uri(String path) {
    final uri = Uri.parse('$baseUrl$path');
    if (kReleaseMode && uri.scheme != 'https') {
      throw ApiException(0, 'insecure_server',
          'This version of the app is set up wrongly (no secure connection). Please update the app.');
    }
    return uri;
  }

  final FlutterSecureStorage _storage = const FlutterSecureStorage();
  String? _token;

  bool get loggedIn => _token != null;

  Future<void> loadToken() async {
    _token = await _storage.read(key: 'token');
  }

  Future<void> _saveToken(String? token) async {
    _token = token;
    if (token == null) {
      await _storage.delete(key: 'token');
    } else {
      await _storage.write(key: 'token', value: token);
    }
  }

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Duration timeout = const Duration(seconds: 30),
  }) async {
    final request = http.Request(method, _uri(path))
      ..headers['Content-Type'] = 'application/json';
    if (_token != null) {
      request.headers['Authorization'] = 'Bearer $_token';
    }
    if (body != null) {
      request.body = jsonEncode(body);
    }

    http.Response res;
    try {
      final streamed = await request.send().timeout(timeout);
      res = await http.Response.fromStream(streamed);
    } on Exception {
      // SocketException, TimeoutException, ...
      throw ApiException(0, 'network',
          'We could not reach the server. Please check your internet connection and try again.');
    }

    dynamic json;
    if (res.body.isNotEmpty) {
      try {
        json = jsonDecode(res.body);
      } on FormatException {
        json = null;
      }
    }

    if (res.statusCode >= 400) {
      final err = (json is Map && json['error'] is Map) ? json['error'] as Map : {};
      // PHP encodes an empty array as [] rather than {}, so check before casting.
      final extra = err['extra'] is Map
          ? Map<String, dynamic>.from(err['extra'] as Map)
          : <String, dynamic>{};
      throw ApiException(
        res.statusCode,
        (err['code'] ?? 'error').toString(),
        (err['message'] ?? 'Something went wrong. Please try again.').toString(),
        extra,
      );
    }
    return json is Map ? json['data'] : null;
  }

  Map<String, dynamic> _map(dynamic v) => Map<String, dynamic>.from(v as Map);

  // ---- auth ----
  Future<void> register(String email, String password) async {
    final data = await _send('POST', '/api/register',
        body: {'email': email, 'password': password});
    await _saveToken(_map(data)['token'] as String);
  }

  Future<void> login(String email, String password) async {
    final data = await _send('POST', '/api/login',
        body: {'email': email, 'password': password});
    await _saveToken(_map(data)['token'] as String);
  }

  /// Signs out on the server too, so the old token stops working even if it was copied.
  /// The phone forgets the token whatever happens (offline, expired session, ...).
  Future<void> logout() async {
    if (_token != null) {
      try {
        await _send('POST', '/api/logout', timeout: const Duration(seconds: 5));
      } on ApiException {
        // Already invalid or offline: nothing more to do.
      }
    }
    await _saveToken(null);
  }

  /// Deletes the account and everything in it. The password is checked again on the server.
  Future<void> deleteAccount(String password) async {
    await _send('DELETE', '/api/account', body: {'password': password});
    await _saveToken(null);
  }

  // ---- drinks ----
  /// Common drinks for the "usual drinks" picker: key, name, serving, kcal, alcohol.
  Future<List<Map<String, dynamic>>> drinks() async {
    final data = await _send('GET', '/api/drinks');
    return (data as List).map((d) => Map<String, dynamic>.from(d as Map)).toList();
  }

  // ---- profile ----
  /// Returns {profile: Map?, targets: Map?}
  Future<Map<String, dynamic>> getProfile() async =>
      _map(await _send('GET', '/api/profile'));

  Future<Map<String, dynamic>> saveProfile(Map<String, dynamic> body) async =>
      _map(await _send('PUT', '/api/profile', body: body));

  // ---- family ----
  /// Everyone the account cooks for: id, name, kind (adult/child), is_owner, age, kcal, ready.
  Future<List<Map<String, dynamic>>> household() async {
    final data = _map(await _send('GET', '/api/household'));
    return (data['members'] as List).map((m) => Map<String, dynamic>.from(m as Map)).toList();
  }

  /// Returns {kind, profile, targets}.
  Future<Map<String, dynamic>> getMember(int id) async =>
      _map(await _send('GET', '/api/members/$id'));

  Future<Map<String, dynamic>> createMember(Map<String, dynamic> body) async =>
      _map(await _send('POST', '/api/members', body: body));

  Future<Map<String, dynamic>> updateMember(int id, Map<String, dynamic> body) async =>
      _map(await _send('PUT', '/api/members/$id', body: body));

  Future<void> deleteMember(int id) async {
    await _send('DELETE', '/api/members/$id');
  }

  // ---- plans ----
  /// Starts building the household's week in the background. Returns at once with status
  /// "building"; poll [latestPlan] until it becomes "ready" or "failed".
  Future<Map<String, dynamic>> startPlan() async =>
      _map(await _send('POST', '/api/plans/generate'));

  Future<Map<String, dynamic>?> latestPlan() async {
    final d = await _send('GET', '/api/plans/latest');
    return d == null ? null : _map(d);
  }

  Future<Map<String, dynamic>> grocery(int planId) async =>
      _map(await _send('GET', '/api/plans/$planId/grocery'));

  // ---- what is at home ----
  /// Every food the app knows: id, name, unit, category (for adding a food by hand).
  Future<List<Map<String, dynamic>>> ingredients() async {
    final data = await _send('GET', '/api/ingredients');
    return (data as List).map((d) => Map<String, dynamic>.from(d as Map)).toList();
  }

  /// The saved list that is still in date: {items, hours_left, valid_days, plan_days}.
  Future<Map<String, dynamic>> pantry() async => _map(await _send('GET', '/api/pantry'));

  /// Saves the reviewed list. Each item needs ingredient_id and amount.
  Future<Map<String, dynamic>> savePantry(List<Map<String, dynamic>> items) async =>
      _map(await _send('PUT', '/api/pantry', body: {'items': items}));

  Future<void> clearPantry() async {
    await _send('DELETE', '/api/pantry');
  }

  /// Sends photos (base64) to be read. Returns {items, unmatched}; nothing is saved yet.
  Future<Map<String, dynamic>> scanPantry(List<String> base64Images) async => _map(await _send(
        'POST',
        '/api/pantry/scan',
        body: {
          'images': [
            for (final data in base64Images) {'data': data}
          ],
        },
        timeout: const Duration(seconds: 120),
      ));
}
