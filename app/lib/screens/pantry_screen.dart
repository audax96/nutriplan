import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../ui.dart';
import '../util.dart';

const _maxPhotos = 4;
const _photoSize = 1280.0;

/// "What do you have at home?" Take photos of the fridge, freezer or cupboard, check the list
/// we find, and the first days of the plan will use those foods.
///
/// Pops with 'build' (saved, and the person wants the week built now), 'saved' or null.
class PantryScreen extends StatefulWidget {
  const PantryScreen({super.key});

  @override
  State<PantryScreen> createState() => _PantryScreenState();
}

class _PantryScreenState extends State<PantryScreen> {
  final _picker = ImagePicker();

  /// Photos live only in memory, and are dropped as soon as they have been read.
  final List<Uint8List> _photos = [];
  List<Map<String, dynamic>> _items = [];
  List<String> _unmatched = [];
  List<Map<String, dynamic>>? _allFoods;

  bool _loading = true;
  bool _scanning = false;
  bool _saving = false;
  bool _hadSaved = false;
  int? _hoursLeft;
  String? _error;
  String? _notice;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// How many days at the start of the plan are built around these foods. A longer list
  /// gets more days. Keep in step with Pantry::planDays() in the backend.
  int get _planDays => _items.length <= 8 ? 3 : (_items.length <= 14 ? 4 : 5);

  // ---------- data ----------

  Map<String, dynamic> _item(Map raw) => {
        'ingredient_id': (raw['ingredient_id'] as num).toInt(),
        'name': raw['name'].toString(),
        'unit': raw['unit'].toString(),
        'category': raw['category'].toString(),
        'amount': toDouble(raw['amount']) ?? 100.0,
      };

  Future<void> _load() async {
    try {
      final data = await Api.instance.pantry();
      if (!mounted) return;
      setState(() {
        _items = [for (final i in (data['items'] as List)) _item(i as Map)];
        _hadSaved = _items.isNotEmpty;
        _hoursLeft = data['hours_left'] is num ? (data['hours_left'] as num).toInt() : null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  // ---------- photos ----------

  void _tooMany() {
    setState(() => _notice = 'You can use up to $_maxPhotos photos at a time.');
  }

  Future<void> _takePhoto() async {
    if (_photos.length >= _maxPhotos) return _tooMany();
    try {
      final file = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: _photoSize,
        maxHeight: _photoSize,
        imageQuality: 80,
      );
      if (file == null) return;
      final bytes = await file.readAsBytes();
      if (!mounted) return;
      setState(() {
        _photos.add(bytes);
        _error = null;
        _notice = null;
      });
    } on Exception {
      if (mounted) {
        setState(() => _error =
            'We could not open the camera. Please check that NutriPlan is allowed to use it in your phone\'s settings, or choose photos instead.');
      }
    }
  }

  Future<void> _choosePhotos() async {
    final room = _maxPhotos - _photos.length;
    if (room <= 0) return _tooMany();
    try {
      final files = await _picker.pickMultiImage(
        maxWidth: _photoSize,
        maxHeight: _photoSize,
        imageQuality: 80,
      );
      if (files.isEmpty) return;
      final chosen = <Uint8List>[];
      for (final f in files.take(room)) {
        chosen.add(await f.readAsBytes());
      }
      if (!mounted) return;
      setState(() {
        _photos.addAll(chosen);
        _error = null;
        _notice = files.length > room ? 'We used the first $room photos. The most you can use is $_maxPhotos.' : null;
      });
    } on Exception {
      if (mounted) {
        setState(() => _error = 'We could not open your photos. Please try again.');
      }
    }
  }

  Future<void> _scan() async {
    setState(() {
      _scanning = true;
      _error = null;
      _notice = null;
    });
    try {
      final data = await Api.instance.scanPantry([for (final p in _photos) base64Encode(p)]);
      if (!mounted) return;
      final found = [for (final i in (data['items'] as List)) _item(i as Map)];
      final byId = {for (final i in _items) i['ingredient_id'] as int: i};
      for (final f in found) {
        final id = f['ingredient_id'] as int;
        final old = byId[id];
        if (old == null || (f['amount'] as double) > (old['amount'] as double)) byId[id] = f;
      }
      setState(() {
        _items = byId.values.toList()
          ..sort((a, b) => a['name'].toString().compareTo(b['name'].toString()));
        _unmatched = [for (final u in (data['unmatched'] as List)) u.toString()];
        _photos.clear(); // the photos are no longer needed
        if (found.isEmpty) {
          _notice =
              'We could not find any foods we know in those photos. Try a clearer photo, or add foods by hand.';
        }
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _scanning = false);
    }
  }

  // ---------- the list ----------

  void _change(int index, double delta) {
    final a = (_items[index]['amount'] as double) + delta;
    setState(() => _items[index]['amount'] = a < 10 ? 10.0 : (a > 5000 ? 5000.0 : a));
  }

  void _remove(int index) => setState(() => _items.removeAt(index));

  /// A rough starting amount, which the person adjusts on the list.
  double _startAmount(String category) {
    switch (category) {
      case 'meat':
      case 'grain':
      case 'beverage':
        return 500;
      case 'legume':
        return 400;
      case 'fish':
      case 'egg':
      case 'vegetable':
      case 'fruit':
        return 300;
      case 'dairy':
        return 250;
      default:
        return 100;
    }
  }

  Future<void> _addByHand() async {
    try {
      _allFoods ??= await Api.instance.ingredients();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
      return;
    }
    if (!mounted) return;
    final taken = {for (final i in _items) i['ingredient_id'] as int};
    final picked = await showModalBottomSheet<List<Map<String, dynamic>>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      builder: (_) => _FoodPicker(
        foods: [
          for (final f in _allFoods!)
            if (!taken.contains((f['id'] as num).toInt())) f
        ],
      ),
    );
    if (picked == null || picked.isEmpty) return;
    setState(() {
      for (final f in picked) {
        _items.add({
          'ingredient_id': (f['id'] as num).toInt(),
          'name': f['name'].toString(),
          'unit': f['unit'].toString(),
          'category': f['category'].toString(),
          'amount': _startAmount(f['category'].toString()),
        });
      }
      _items.sort((a, b) => a['name'].toString().compareTo(b['name'].toString()));
      _notice = 'We added ${picked.length} ${picked.length == 1 ? 'food' : 'foods'}. Please check the amounts below.';
    });
  }

  Future<void> _save({required bool build}) async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await Api.instance.savePantry([
        for (final i in _items) {'ingredient_id': i['ingredient_id'], 'amount': i['amount']}
      ]);
      if (!mounted) return;
      Navigator.of(context).pop(build ? 'build' : 'saved');
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _clearSaved() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await Api.instance.clearPantry();
      if (!mounted) return;
      Navigator.of(context).pop('saved');
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  // ---------- build ----------

  @override
  Widget build(BuildContext context) {
    final busy = _scanning || _saving;
    return Scaffold(
      appBar: appBar('At home', backLabel: 'Back'),
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
                children: [
                  StepHeading('What do you have at home?',
                      subtitle:
                          'Take a photo of your fridge, freezer or cupboard. Your first $_planDays days will be planned around these foods, and every one of them will be used. They also come off your shopping list.'),
                  if (_error != null) ...[
                    Banner2(_error!, isError: true),
                    const SizedBox(height: 16),
                  ],
                  if (_notice != null) ...[
                    Banner2(_notice!),
                    const SizedBox(height: 16),
                  ],
                  FilledButton.icon(
                    onPressed: busy ? null : _takePhoto,
                    icon: const Icon(Icons.photo_camera_outlined, size: 28),
                    label: const Text('Take a photo'),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: busy ? null : _choosePhotos,
                    icon: const Icon(Icons.photo_library_outlined, size: 26),
                    label: const Text('Choose photos'),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Your photos are sent securely to Claude, the AI that plans your meals, only to find the foods. We do not save them on our server.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  if (_photos.isNotEmpty) ..._photoSection(busy),
                  if (_items.isNotEmpty) ..._listSection(),
                  if (_unmatched.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    Banner2(
                        'We could not match these to our food list, so they will not be used: ${_unmatched.join(', ')}. You can add something similar by hand.'),
                  ],
                  const SizedBox(height: 20),
                  OutlinedButton.icon(
                    onPressed: busy ? null : _addByHand,
                    icon: const Icon(Icons.add, size: 28),
                    label: const Text('Add foods by hand'),
                  ),
                  if (_items.isNotEmpty) ...[
                    const SizedBox(height: 28),
                    FilledButton(
                      onPressed: busy ? null : () => _save(build: true),
                      child: _saving ? const ButtonSpinner() : const Text('Save and build my week'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: busy ? null : () => _save(build: false),
                      child: const Text('Save for later'),
                    ),
                  ] else if (_hadSaved) ...[
                    const SizedBox(height: 28),
                    OutlinedButton(
                      onPressed: busy ? null : _clearSaved,
                      child: const Text('Clear my saved list'),
                    ),
                  ],
                ],
              ),
      ),
    );
  }

  List<Widget> _photoSection(bool busy) {
    return [
      const SizedBox(height: 20),
      SectionTitle('Your photos', hint: '${_photos.length} of $_maxPhotos'),
      SizedBox(
        height: 116,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          itemCount: _photos.length,
          separatorBuilder: (_, __) => const SizedBox(width: 10),
          itemBuilder: (context, i) => Stack(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: Image.memory(_photos[i], width: 116, height: 116, fit: BoxFit.cover),
              ),
              Positioned(
                top: 4,
                right: 4,
                child: Material(
                  color: AppColors.surface,
                  shape: const CircleBorder(),
                  child: InkWell(
                    customBorder: const CircleBorder(),
                    onTap: busy ? null : () => setState(() => _photos.removeAt(i)),
                    child: const Padding(
                      padding: EdgeInsets.all(8),
                      child: Icon(Icons.close, size: 24, color: AppColors.text),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      const SizedBox(height: 16),
      FilledButton(
        onPressed: busy ? null : _scan,
        child: _scanning
            ? const Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  ButtonSpinner(),
                  SizedBox(width: 14),
                  Text('Looking at your photos...'),
                ],
              )
            : const Text('Find the foods in my photos'),
      ),
    ];
  }

  List<Widget> _listSection() {
    final days = _hoursLeft == null ? null : ((_hoursLeft! / 24).ceil());
    return [
      const SizedBox(height: 28),
      SectionTitle(
        'Your list',
        hint: 'Please check this is right. Change an amount, or remove anything we got wrong.'
            '${_hadSaved && days != null && days > 0 ? ' Your saved list stays in date for about $days more ${days == 1 ? 'day' : 'days'}.' : ''}',
      ),
      const Banner2(
          'The amounts are only estimates from your photos. Please check each one against the packet or what you really have, and change any that look wrong.'),
      const SizedBox(height: 12),
      for (var i = 0; i < _items.length; i++) _itemCard(i),
    ];
  }

  Widget _itemCard(int index) {
    final item = _items[index];
    final amount = item['amount'] as double;
    final step = amount < 200 ? 25.0 : 50.0;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: AppCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(item['name'].toString(), style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 10),
            Row(
              children: [
                _roundButton(Icons.remove, 'Less ${item['name']}', () => _change(index, -step)),
                Expanded(
                  child: Center(
                    child: Text('${fmtNum(amount)} ${item['unit']}',
                        style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
                  ),
                ),
                _roundButton(Icons.add, 'More ${item['name']}', () => _change(index, step)),
              ],
            ),
            const SizedBox(height: 4),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: () => _remove(index),
                style: TextButton.styleFrom(foregroundColor: AppColors.error),
                icon: const Icon(Icons.delete_outline, size: 24),
                label: const Text('Remove'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _roundButton(IconData icon, String label, VoidCallback onTap) {
    return Semantics(
      button: true,
      label: label,
      child: Material(
        color: AppColors.surfaceHigh,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: SizedBox(
            width: 60,
            height: 60,
            child: Icon(icon, size: 32, color: AppColors.accent),
          ),
        ),
      ),
    );
  }
}

/// A searchable list of every food. Tap as many as you like, then add them all at once.
class _FoodPicker extends StatefulWidget {
  const _FoodPicker({required this.foods});

  final List<Map<String, dynamic>> foods;

  @override
  State<_FoodPicker> createState() => _FoodPickerState();
}

class _FoodPickerState extends State<_FoodPicker> {
  String _query = '';
  final Map<int, Map<String, dynamic>> _chosen = {};

  void _toggle(Map<String, dynamic> food) {
    final id = (food['id'] as num).toInt();
    setState(() {
      if (_chosen.containsKey(id)) {
        _chosen.remove(id);
      } else {
        _chosen[id] = food;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final q = _query.trim().toLowerCase();
    final shown = [
      for (final f in widget.foods)
        if (q.isEmpty || f['name'].toString().toLowerCase().contains(q)) f
    ];
    final n = _chosen.length;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.85,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 16, 12, 0),
              child: Row(
                children: [
                  Expanded(
                    child: Text('Add foods', style: Theme.of(context).textTheme.titleLarge),
                  ),
                  TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text('Tap every food you have, then tap Add.',
                    style: Theme.of(context).textTheme.bodySmall),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
              child: TextField(
                style: const TextStyle(fontSize: 20),
                decoration: fieldDecoration('Search, for example eggs', suffixIcon: const Icon(Icons.search)),
                onChanged: (v) => setState(() => _query = v),
              ),
            ),
            Expanded(
              child: shown.isEmpty
                  ? const Padding(
                      padding: EdgeInsets.all(24),
                      child: Text('We do not have that food yet. Try another name.',
                          style: TextStyle(fontSize: 18)),
                    )
                  : ListView.builder(
                      itemCount: shown.length,
                      itemBuilder: (context, i) {
                        final f = shown[i];
                        final on = _chosen.containsKey((f['id'] as num).toInt());
                        return ListTile(
                          minVerticalPadding: 12,
                          selected: on,
                          selectedTileColor: AppColors.surfaceHigh,
                          title: Text(f['name'].toString(),
                              style: TextStyle(fontSize: 20, fontWeight: on ? FontWeight.w700 : FontWeight.w400)),
                          subtitle: Text(capitalise(f['category'].toString().replaceAll('_', ' ')),
                              style: const TextStyle(fontSize: 16, color: AppColors.muted)),
                          trailing: Icon(on ? Icons.check_box : Icons.check_box_outline_blank,
                              size: 32, color: on ? AppColors.accent : AppColors.muted),
                          onTap: () => _toggle(f),
                        );
                      },
                    ),
            ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
                child: FilledButton(
                  onPressed: n == 0 ? null : () => Navigator.of(context).pop(_chosen.values.toList()),
                  child: Text(n == 0 ? 'Add foods' : 'Add $n ${n == 1 ? 'food' : 'foods'}'),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
