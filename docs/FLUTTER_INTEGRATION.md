# 📱 Integración del Sistema de Presupuestos en Flutter

## Resumen

Guía para integrar los endpoints del sistema de presupuestos en tu aplicación Flutter.

---

## 🔐 Autenticación

Todos los endpoints requieren token Bearer. Después de login, usas el token:

```dart
final token = 'tu_token_de_sanctum';
final headers = {
  'Authorization': 'Bearer $token',
  'Content-Type': 'application/json',
};
```

---

## 📊 MODO 1: Presupuesto Manual

### Pantalla: Crear Presupuesto Manual

```dart
import 'package:http/http.dart' as http;
import 'dart:convert';

class BudgetService {
  final String baseUrl = 'http://tu-servidor.com/api';
  final String token;

  BudgetService({required this.token});

  Future<Map<String, dynamic>> createManualBudget({
    required String title,
    required String description,
    required double totalAmount,
    required List<CategoryData> categories,
  }) async {
    final headers = {
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/json',
    };

    final body = {
      'title': title,
      'description': description,
      'total_amount': totalAmount,
      'categories': categories.map((cat) => {
        'name': cat.name,
        'amount': cat.amount,
        'reason': cat.reason ?? '',
      }).toList(),
    };

    try {
      final response = await http.post(
        Uri.parse('$baseUrl/budgets/manual'),
        headers: headers,
        body: jsonEncode(body),
      );

      if (response.statusCode == 201) {
        return jsonDecode(response.body);
      } else {
        throw Exception('Error: ${response.statusCode}');
      }
    } catch (e) {
      rethrow;
    }
  }
}

// Modelo
class CategoryData {
  final String name;
  final double amount;
  final String? reason;

  CategoryData({
    required this.name,
    required this.amount,
    this.reason,
  });
}
```

### Widget: Formulario de categorías

```dart
class BudgetCategoryForm extends StatefulWidget {
  @override
  State<BudgetCategoryForm> createState() => _BudgetCategoryFormState();
}

class _BudgetCategoryFormState extends State<BudgetCategoryForm> {
  final List<CategoryData> categories = [];
  final double totalAmount = 2000;
  double currentSum = 0;

  void addCategory(String name, double amount, String? reason) {
    if (currentSum + amount <= totalAmount) {
      setState(() {
        categories.add(CategoryData(
          name: name,
          amount: amount,
          reason: reason,
        ));
        currentSum += amount;
      });
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Excede el presupuesto total')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final remaining = totalAmount - currentSum;

    return Column(
      children: [
        Text('Total: \$$totalAmount'),
        Text('Utilizado: \$$currentSum'),
        Text('Disponible: \$$remaining', 
             style: TextStyle(
               color: remaining > 0 ? Colors.green : Colors.red,
             )),
        Divider(),
        ListView.builder(
          itemCount: categories.length,
          itemBuilder: (context, index) {
            final cat = categories[index];
            return ListTile(
              title: Text(cat.name),
              subtitle: Text(cat.reason ?? ''),
              trailing: Text('\$${cat.amount}'),
              onLongPress: () {
                setState(() {
                  currentSum -= cat.amount;
                  categories.removeAt(index);
                });
              },
            );
          },
        ),
      ],
    );
  }
}
```

---

## 🤖 MODO 2: Presupuesto con IA

### Pantalla 1: Generar sugerencias de IA

```dart
class AIBudgetScreen extends StatefulWidget {
  @override
  State<AIBudgetScreen> createState() => _AIBudgetScreenState();
}

class _AIBudgetScreenState extends State<AIBudgetScreen> {
  final titleController = TextEditingController();
  final descriptionController = TextEditingController();
  final totalAmountController = TextEditingController();
  
  List<Map<String, dynamic>> suggestedCategories = [];
  bool isLoading = false;

  Future<void> generateSuggestions() async {
    setState(() => isLoading = true);
    
    try {
      final response = await http.post(
        Uri.parse('${baseUrl}/budgets/ai/generate'),
        headers: headers,
        body: jsonEncode({
          'title': titleController.text,
          'description': descriptionController.text,
          'total_amount': double.parse(totalAmountController.text),
        }),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        setState(() {
          suggestedCategories = List<Map<String, dynamic>>.from(
            data['data']['categories'] ?? []
          );
        });

        // Navegar a pantalla de revisión
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (context) => AIBudgetReviewScreen(
              suggestedCategories: suggestedCategories,
              title: titleController.text,
              description: descriptionController.text,
              totalAmount: double.parse(totalAmountController.text),
            ),
          ),
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: $e')),
      );
    } finally {
      setState(() => isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Presupuesto con IA')),
      body: SingleChildScrollView(
        padding: EdgeInsets.all(16),
        child: Column(
          children: [
            TextField(
              controller: titleController,
              decoration: InputDecoration(
                label: Text('Título del presupuesto'),
                hint: 'Ej: Viaje a Perú',
              ),
            ),
            SizedBox(height: 16),
            TextField(
              controller: descriptionController,
              decoration: InputDecoration(
                label: Text('Descripción'),
                hint: 'Describe tu plan...',
              ),
              maxLines: 4,
            ),
            SizedBox(height: 16),
            TextField(
              controller: totalAmountController,
              decoration: InputDecoration(
                label: Text('Monto total'),
                prefixText: '\$',
              ),
              keyboardType: TextInputType.number,
            ),
            SizedBox(height: 24),
            isLoading
                ? CircularProgressIndicator()
                : ElevatedButton(
                    onPressed: generateSuggestions,
                    child: Text('Generar sugerencias de IA'),
                  ),
          ],
        ),
      ),
    );
  }

  @override
  void dispose() {
    titleController.dispose();
    descriptionController.dispose();
    totalAmountController.dispose();
    super.dispose();
  }
}
```

### Pantalla 2: Revisar y editar sugerencias

```dart
class AIBudgetReviewScreen extends StatefulWidget {
  final List<Map<String, dynamic>> suggestedCategories;
  final String title;
  final String description;
  final double totalAmount;

  const AIBudgetReviewScreen({
    required this.suggestedCategories,
    required this.title,
    required this.description,
    required this.totalAmount,
  });

  @override
  State<AIBudgetReviewScreen> createState() => _AIBudgetReviewScreenState();
}

class _AIBudgetReviewScreenState extends State<AIBudgetReviewScreen> {
  late List<Map<String, dynamic>> editedCategories;
  
  @override
  void initState() {
    super.initState();
    editedCategories = List.from(widget.suggestedCategories);
  }

  void editCategory(int index, {
    String? name,
    double? amount,
    String? reason,
  }) {
    setState(() {
      if (name != null) editedCategories[index]['name'] = name;
      if (amount != null) editedCategories[index]['amount'] = amount;
      if (reason != null) editedCategories[index]['reason'] = reason;
    });
  }

  void deleteCategory(int index) {
    setState(() => editedCategories.removeAt(index));
  }

  void addCategory() {
    showDialog(
      context: context,
      builder: (context) => AddCategoryDialog(
        onAdd: (name, amount, reason) {
          setState(() => editedCategories.add({
            'name': name,
            'amount': amount,
            'reason': reason,
          }));
          Navigator.pop(context);
        },
      ),
    );
  }

  Future<void> saveAIBudget() async {
    // Validar que la suma sea exacta
    final sum = editedCategories.fold<double>(
      0,
      (prev, cat) => prev + (cat['amount'] as double),
    );

    if ((sum - widget.totalAmount).abs() > 0.01) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Las categorías deben sumar exactamente \$${widget.totalAmount}. '
            'Actual: \$$sum'
          ),
        ),
      );
      return;
    }

    try {
      final response = await http.post(
        Uri.parse('${baseUrl}/budgets/ai/save'),
        headers: headers,
        body: jsonEncode({
          'title': widget.title,
          'description': widget.description,
          'total_amount': widget.totalAmount,
          'language': 'es', // o 'en'
          'plan_type': 'travel', // auto-detectado en backend
          'categories': editedCategories,
        }),
      );

      if (response.statusCode == 201) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('¡Presupuesto guardado exitosamente!')),
        );
        Navigator.pop(context);
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error: $e')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final sum = editedCategories.fold<double>(
      0,
      (prev, cat) => prev + (cat['amount'] as double),
    );
    final isValid = (sum - widget.totalAmount).abs() <= 0.01;

    return Scaffold(
      appBar: AppBar(title: Text('Revisar sugerencias de IA')),
      body: Column(
        children: [
          Padding(
            padding: EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(widget.title, style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
                SizedBox(height: 8),
                Text('Total: \$${widget.totalAmount}'),
                Text('Actual: \$$sum'),
                SizedBox(height: 8),
                Container(
                  padding: EdgeInsets.all(8),
                  color: isValid ? Colors.green[100] : Colors.red[100],
                  child: Text(
                    isValid ? '✅ Presupuesto válido' : '❌ Las categorías no suman correctamente',
                    style: TextStyle(
                      color: isValid ? Colors.green[900] : Colors.red[900],
                    ),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: editedCategories.length,
              itemBuilder: (context, index) {
                final cat = editedCategories[index];
                return ListTile(
                  title: Text(cat['name'] ?? ''),
                  subtitle: Text(cat['reason'] ?? ''),
                  trailing: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('\$${cat['amount']}'),
                    ],
                  ),
                  onTap: () {
                    // Editar categoría
                    showDialog(
                      context: context,
                      builder: (context) => EditCategoryDialog(
                        category: cat,
                        onSave: (name, amount, reason) {
                          editCategory(index, name: name, amount: amount, reason: reason);
                          Navigator.pop(context);
                        },
                      ),
                    );
                  },
                  onLongPress: () => deleteCategory(index),
                );
              },
            ),
          ),
          Padding(
            padding: EdgeInsets.all(16),
            child: Row(
              children: [
                ElevatedButton(
                  onPressed: addCategory,
                  child: Text('Agregar categoría'),
                ),
                SizedBox(width: 16),
                ElevatedButton(
                  onPressed: isValid ? saveAIBudget : null,
                  child: Text('Guardar presupuesto'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
```

---

## 📋 Listar y visualizar presupuestos

```dart
class BudgetsListScreen extends StatefulWidget {
  @override
  State<BudgetsListScreen> createState() => _BudgetsListScreenState();
}

class _BudgetsListScreenState extends State<BudgetsListScreen> {
  late Future<List<Map<String, dynamic>>> budgets;

  @override
  void initState() {
    super.initState();
    budgets = fetchBudgets();
  }

  Future<List<Map<String, dynamic>>> fetchBudgets() async {
    try {
      final response = await http.get(
        Uri.parse('${baseUrl}/budgets?status=active'),
        headers: headers,
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return List<Map<String, dynamic>>.from(data['data']['data'] ?? []);
      }
    } catch (e) {
      print('Error: $e');
    }
    return [];
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Mis Presupuestos')),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: budgets,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          }

          if (!snapshot.hasData || snapshot.data!.isEmpty) {
            return Center(child: Text('No hay presupuestos'));
          }

          return ListView.builder(
            itemCount: snapshot.data!.length,
            itemBuilder: (context, index) {
              final budget = snapshot.data![index];
              return ListTile(
                title: Text(budget['title'] ?? ''),
                subtitle: Text(budget['mode'] == 'ai' ? '🤖 IA' : '✍️ Manual'),
                trailing: Text('\$${budget['total_amount']}'),
                onTap: () {
                  // Ver detalles del presupuesto
                },
              );
            },
          );
        },
      ),
      floatingActionButton: Column(
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          FloatingActionButton(
            heroTag: 'manual',
            onPressed: () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (context) => BudgetManualScreen()),
              );
            },
            child: Icon(Icons.edit),
            tooltip: 'Crear manual',
          ),
          SizedBox(height: 16),
          FloatingActionButton(
            heroTag: 'ai',
            onPressed: () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (context) => AIBudgetScreen()),
              );
            },
            child: Icon(Icons.auto_awesome),
            tooltip: 'Con IA',
          ),
        ],
      ),
    );
  }
}
```

---

## 🛠️ Modelo Dart completo

```dart
class Budget {
  final int id;
  final String title;
  final String description;
  final double totalAmount;
  final String mode; // 'manual' o 'ai'
  final String language; // 'es' o 'en'
  final String planType; // 'travel', 'event', 'party', 'purchase', 'project', 'other'
  final String status; // 'draft', 'active', 'archived'
  final List<BudgetCategory> categories;
  final DateTime createdAt;

  Budget({
    required this.id,
    required this.title,
    required this.description,
    required this.totalAmount,
    required this.mode,
    required this.language,
    required this.planType,
    required this.status,
    required this.categories,
    required this.createdAt,
  });

  factory Budget.fromJson(Map<String, dynamic> json) {
    return Budget(
      id: json['id'],
      title: json['title'],
      description: json['description'],
      totalAmount: (json['total_amount'] as num).toDouble(),
      mode: json['mode'],
      language: json['language'],
      planType: json['plan_type'],
      status: json['status'],
      categories: (json['categories'] as List)
          .map((cat) => BudgetCategory.fromJson(cat))
          .toList(),
      createdAt: DateTime.parse(json['created_at']),
    );
  }

  double get categoriesTotal => 
    categories.fold(0, (prev, cat) => prev + cat.amount);

  bool get isValid => (categoriesTotal - totalAmount).abs() <= 0.01;
}

class BudgetCategory {
  final int id;
  final String name;
  final double amount;
  final double percentage;
  final String reason;

  BudgetCategory({
    required this.id,
    required this.name,
    required this.amount,
    required this.percentage,
    required this.reason,
  });

  factory BudgetCategory.fromJson(Map<String, dynamic> json) {
    return BudgetCategory(
      id: json['id'],
      name: json['name'],
      amount: (json['amount'] as num).toDouble(),
      percentage: (json['percentage'] as num).toDouble(),
      reason: json['reason'] ?? '',
    );
  }
}
```

---

## 🎯 Mejores prácticas

1. **Validación local**: Valida que la suma sea correcta antes de enviar
2. **Loading states**: Muestra indicador de carga mientras se genera con IA
3. **Error handling**: Captura errores de conexión y API
4. **Caché**: Guarda presupuestos localmente con shared_preferences
5. **Confirmación**: Confirma antes de eliminar presupuestos

---

## 📦 Dependencias recomendadas

```yaml
dependencies:
  flutter:
    sdk: flutter
  http: ^1.1.0
  provider: ^6.0.0
  shared_preferences: ^2.0.0
  intl: ^0.19.0
```

---

¡Integración lista! 🚀

