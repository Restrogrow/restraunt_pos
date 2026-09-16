import { Ionicons } from '@expo/vector-icons';
import * as ImagePicker from 'expo-image-picker';
import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import SelectField from '../components/SelectField';
import { apiGet, apiPostForm, imageUrl as resolveImageUrl } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { colors, font, radius, shadow, spacing } from '../theme';
import { emitMenuChange } from '../utils/menuChangeBus';

const TYPE_OPTIONS = ['Veg', 'Non Veg', 'Egg', 'Drink', 'Dessert', 'Other'].map((t) => ({ label: t, value: t }));
const STOCK_OPTIONS = [
  { label: 'In Stock', value: 1 },
  { label: 'Out of Stock', value: 0 },
];

function Row({ label, value }) {
  if (!value && value !== 0) return null;
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value}</Text>
    </View>
  );
}

function Header({ title, onBack, right, insets }) {
  return (
    <View style={[styles.header, { paddingTop: insets.top + spacing.sm }]}>
      <Pressable style={styles.iconButton} onPress={onBack} hitSlop={8}>
        <Ionicons name="arrow-back" size={20} color={colors.ink} />
      </Pressable>
      <Text style={styles.headerTitle} numberOfLines={1}>{title}</Text>
      {right}
    </View>
  );
}

function emptyVariation() {
  return { key: String(Math.random()), variation_name: '', price: '' };
}

export default function MenuItemScreen({ route, navigation }) {
  const initialItem = route.params?.item || null;
  const isCreate = !initialItem;
  const { user } = useAuth();
  const insets = useSafeAreaInsets();
  const currency = user?.currency_symbol || '₹';

  const [item, setItem] = useState(initialItem);
  const [editing, setEditing] = useState(isCreate);
  const [menus, setMenus] = useState([]);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [togglingHidden, setTogglingHidden] = useState(false);

  const [name, setName] = useState('');
  const [menuId, setMenuId] = useState('');
  const [subcategoryId, setSubcategoryId] = useState('');
  const [description, setDescription] = useState('');
  const [descriptionFormat, setDescriptionFormat] = useState('paragraph');
  const [category, setCategory] = useState('');
  const [type, setType] = useState('');
  const [price, setPrice] = useState('');
  const [prepTime, setPrepTime] = useState('');
  const [calories, setCalories] = useState('');
  const [available, setAvailable] = useState(1);
  const [hasVariations, setHasVariations] = useState(false);
  const [variations, setVariations] = useState([]);
  const [imageAsset, setImageAsset] = useState(null); // { uri, base64, mimeType }
  const [imageUrl, setImageUrl] = useState('');
  const [formError, setFormError] = useState('');

  useEffect(() => {
    let cancelled = false;
    apiGet('/api/get_menus.php')
      .then((res) => {
        if (cancelled || !res.success) return;
        setMenus(res.data || []);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, []);

  const resetFormFromItem = useCallback((src) => {
    setName(src?.item_name_en || '');
    setMenuId(src?.menu_id ? String(src.menu_id) : '');
    setSubcategoryId(src?.subcategory_id ? String(src.subcategory_id) : '');
    setDescription(src?.item_description_en || '');
    setDescriptionFormat(src?.description_format || 'paragraph');
    setCategory(src?.item_category || '');
    setType(src?.item_type || '');
    setPrice(src?.base_price != null ? String(src.base_price) : '');
    setPrepTime(src?.preparation_time != null ? String(src.preparation_time) : '');
    setCalories(src?.calories != null ? String(src.calories) : '');
    setAvailable(src ? (src.is_available == 1 ? 1 : 0) : 1);
    setHasVariations(src ? src.has_variations == 1 : false);
    setVariations(
      (src?.variations || []).map((v) => ({
        key: String(v.id ?? Math.random()),
        variation_name: v.variation_name || '',
        price: v.price != null ? String(v.price) : '',
      }))
    );
    setImageAsset(null);
    setImageUrl('');
    setFormError('');
  }, []);

  // Menu list arrives async; make sure a freshly created item still has a
  // default menu selected once options exist.
  useEffect(() => {
    if (isCreate && !menuId && menus.length > 0) {
      setMenuId(String(menus[0].id));
    }
  }, [isCreate, menus, menuId]);

  useEffect(() => {
    resetFormFromItem(item);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const selectedMenu = menus.find((m) => String(m.id) === String(menuId));
  const subcategories = selectedMenu?.subcategories || [];

  const onSelectMenu = (id) => {
    setMenuId(id);
    const stillValid = (menus.find((m) => String(m.id) === id)?.subcategories || [])
      .some((s) => String(s.id) === String(subcategoryId));
    if (!stillValid) setSubcategoryId('');
  };

  const pickImage = async () => {
    try {
      const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (perm.status !== 'granted' && perm.granted !== true) {
        Alert.alert('Permission needed', 'Allow photo access to choose an image.');
        return;
      }
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        base64: true,
        quality: 0.7,
      });
      if (result.canceled) return;
      const asset = result.assets?.[0];
      if (!asset?.base64) return;
      setImageAsset({ uri: asset.uri, base64: asset.base64, mimeType: asset.mimeType || 'image/jpeg' });
      setImageUrl('');
    } catch (e) {
      Alert.alert('Could not open photo library', e.message);
    }
  };

  const addVariationRow = () => setVariations((v) => [...v, emptyVariation()]);
  const removeVariationRow = (key) => setVariations((v) => v.filter((row) => row.key !== key));
  const updateVariationRow = (key, field, value) =>
    setVariations((v) => v.map((row) => (row.key === key ? { ...row, [field]: value } : row)));

  const startEdit = () => {
    resetFormFromItem(item);
    setEditing(true);
  };

  const cancelEdit = () => {
    if (isCreate) {
      navigation.goBack();
      return;
    }
    resetFormFromItem(item);
    setEditing(false);
  };

  const save = async () => {
    if (!name.trim()) {
      setFormError('Item name is required');
      return;
    }
    if (!menuId) {
      setFormError('Choose a menu');
      return;
    }
    setFormError('');
    setSaving(true);
    try {
      const fields = {
        action: isCreate ? 'add' : 'update',
        ...(isCreate ? {} : { menuItemId: item.id }),
        itemNameEn: name.trim(),
        chooseMenu: menuId,
        subcategoryId: subcategoryId || 0,
        itemDescriptionEn: description,
        description_format: descriptionFormat,
        itemCategory: category,
        itemType: type || 'Other',
        preparationTime: prepTime || 0,
        calories: calories || 0,
        basePrice: price || 0,
        isAvailable: available ? 1 : 0,
      };
      if (hasVariations) {
        fields.hasVariations = 1;
        fields.variations = JSON.stringify(
          variations
            .filter((v) => v.variation_name.trim())
            .map((v) => ({ variation_name: v.variation_name.trim(), price: v.price || 0 }))
        );
      }
      if (imageAsset) {
        fields.itemImageBase64 = `data:${imageAsset.mimeType};base64,${imageAsset.base64}`;
      } else if (imageUrl.trim()) {
        fields.itemImageUrl = imageUrl.trim();
      }

      const res = await apiPostForm('/controllers/menu_items_operations_base64.php', fields);
      if (!res.success) throw new Error(res.message || 'Could not save item');

      // Splice the change into the list instantly — best-effort merge from
      // what we already know locally, so the list is right the moment you
      // go back. The screen's own silent background reload (see MenuScreen)
      // reconciles anything only the server knows (real image reference,
      // variation ids, translations) a moment later, with no visible refresh.
      const menuName = menus.find((m) => String(m.id) === String(menuId))?.menu_name;
      const subcategoryName = subcategories.find((s) => String(s.id) === String(subcategoryId))?.subcategory_name;
      const optimisticFields = {
        item_name_en: name.trim(),
        menu_id: menuId,
        menu_name: menuName,
        subcategory_id: subcategoryId || null,
        subcategory_name: subcategoryId ? subcategoryName : null,
        item_description_en: description,
        description_format: descriptionFormat,
        item_category: category,
        item_type: type || 'Other',
        preparation_time: prepTime || 0,
        calories: calories || 0,
        base_price: price || 0,
        is_available: available ? 1 : 0,
        has_variations: hasVariations ? 1 : 0,
        variations: hasVariations
          ? variations.filter((v) => v.variation_name.trim()).map((v) => ({
              variation_name: v.variation_name.trim(),
              price: v.price || 0,
            }))
          : [],
        ...(imageAsset ? { item_image: imageAsset.uri } : imageUrl.trim() ? { item_image: imageUrl.trim() } : {}),
      };

      if (isCreate) {
        emitMenuChange('create', { id: res.data?.id, ...optimisticFields });
      } else {
        emitMenuChange('update', { id: item.id, ...optimisticFields });
      }

      navigation.goBack();
    } catch (e) {
      setFormError(e.message);
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = () => {
    Alert.alert(
      'Delete this item?',
      `"${item.item_name_en}" will be removed from your menu. This can't be undone.`,
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Delete', style: 'destructive', onPress: doDelete },
      ]
    );
  };

  const doDelete = async () => {
    setDeleting(true);
    try {
      const res = await apiPostForm('/controllers/menu_items_operations_base64.php', {
        action: 'delete',
        menuItemId: item.id,
      });
      if (!res.success) throw new Error(res.message || 'Could not delete item');
      emitMenuChange('delete', { id: item.id });
      navigation.goBack();
    } catch (e) {
      Alert.alert('Could not delete item', e.message);
    } finally {
      setDeleting(false);
    }
  };

  const toggleHidden = async () => {
    setTogglingHidden(true);
    try {
      const res = await apiPostForm('/controllers/menu_items_operations_base64.php', {
        action: 'toggle_hidden',
        menuItemId: item.id,
      });
      if (!res.success) throw new Error(res.message || 'Could not update item');
      setItem((it) => ({ ...it, is_hidden: res.is_hidden }));
      emitMenuChange('update', { id: item.id, is_hidden: res.is_hidden });
    } catch (e) {
      Alert.alert('Could not update item', e.message);
    } finally {
      setTogglingHidden(false);
    }
  };

  if (editing) {
    const previewUri = imageAsset?.uri || (imageUrl.trim() ? imageUrl.trim() : resolveImageUrl(item?.item_image));

    return (
      <View style={styles.fill}>
        <Header
          title={isCreate ? 'New Item' : 'Edit Item'}
          onBack={cancelEdit}
          insets={insets}
          right={<View style={{ width: 36 }} />}
        />
        <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
        <ScrollView style={styles.fill} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
          <Text style={styles.fieldLabel}>Item name</Text>
          <TextInput
            style={styles.input}
            placeholder="e.g. Butter Chicken"
            placeholderTextColor={colors.muted}
            value={name}
            onChangeText={setName}
          />

          <Text style={styles.fieldLabel}>Menu</Text>
          <SelectField
            placeholder="Choose menu"
            value={menuId}
            onChange={(v) => onSelectMenu(String(v))}
            options={menus.map((m) => ({ label: m.menu_name, value: String(m.id) }))}
          />

          {subcategories.length > 0 ? (
            <>
              <Text style={styles.fieldLabel}>Subcategory</Text>
              <SelectField
                placeholder="None"
                value={subcategoryId}
                onChange={(v) => setSubcategoryId(String(v))}
                options={[
                  { label: 'None', value: '' },
                  ...subcategories.map((s) => ({ label: s.subcategory_name, value: String(s.id) })),
                ]}
              />
            </>
          ) : null}

          <View style={styles.rowFields}>
            <View style={{ flex: 1 }}>
              <Text style={styles.fieldLabel}>Price ({currency})</Text>
              <TextInput
                style={styles.input}
                placeholder="0"
                placeholderTextColor={colors.muted}
                keyboardType="decimal-pad"
                value={price}
                onChangeText={setPrice}
              />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.fieldLabel}>Prep time (min)</Text>
              <TextInput
                style={styles.input}
                placeholder="0"
                placeholderTextColor={colors.muted}
                keyboardType="number-pad"
                value={prepTime}
                onChangeText={setPrepTime}
              />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.fieldLabel}>Calories</Text>
              <TextInput
                style={styles.input}
                placeholder="0"
                placeholderTextColor={colors.muted}
                keyboardType="number-pad"
                value={calories}
                onChangeText={setCalories}
              />
            </View>
          </View>

          <Text style={styles.fieldLabel}>Category</Text>
          <TextInput
            style={styles.input}
            placeholder="e.g. Main Course"
            placeholderTextColor={colors.muted}
            value={category}
            onChangeText={setCategory}
          />

          <Text style={styles.fieldLabel}>Type</Text>
          <SelectField placeholder="Choose type" value={type} onChange={setType} options={TYPE_OPTIONS} />

          <View style={styles.labelRow}>
            <Text style={styles.fieldLabel}>Description</Text>
            <Pressable
              style={styles.formatToggle}
              onPress={() => setDescriptionFormat((f) => (f === 'paragraph' ? 'br' : 'paragraph'))}
            >
              <Ionicons name="reorder-four-outline" size={14} color={colors.primary} />
              <Text style={styles.formatToggleText}>
                {descriptionFormat === 'paragraph' ? 'Paragraph' : 'Line breaks'}
              </Text>
            </Pressable>
          </View>
          <TextInput
            style={[styles.input, styles.textArea]}
            placeholder="Short description shown to customers"
            placeholderTextColor={colors.muted}
            value={description}
            onChangeText={setDescription}
            multiline
            numberOfLines={3}
          />

          <Text style={styles.fieldLabel}>Item image</Text>
          <View style={styles.imageRow}>
            <View style={styles.imagePreviewBox}>
              {previewUri ? (
                <Image source={{ uri: previewUri }} style={styles.imagePreview} />
              ) : (
                <Ionicons name="image-outline" size={26} color={colors.muted} />
              )}
            </View>
            <Pressable style={styles.chooseImageButton} onPress={pickImage}>
              <Ionicons name="cloud-upload-outline" size={16} color={colors.primary} />
              <Text style={styles.chooseImageText}>Choose Photo</Text>
            </Pressable>
          </View>
          <TextInput
            style={[styles.input, { marginTop: spacing.sm }]}
            placeholder="Or paste image URL"
            placeholderTextColor={colors.muted}
            value={imageUrl}
            onChangeText={(v) => {
              setImageUrl(v);
              if (v) setImageAsset(null);
            }}
            autoCapitalize="none"
          />

          <Text style={styles.fieldLabel}>Stock Status</Text>
          <SelectField value={available} onChange={setAvailable} options={STOCK_OPTIONS} />

          <View style={styles.availableRow}>
            <Text style={styles.fieldLabel}>Has variations (sizes)</Text>
            <Switch
              value={hasVariations}
              onValueChange={setHasVariations}
              trackColor={{ false: colors.border, true: colors.primaryLight }}
              thumbColor={hasVariations ? colors.primary : '#fff'}
            />
          </View>

          {hasVariations ? (
            <View style={styles.variationsBox}>
              {variations.map((v) => (
                <View key={v.key} style={styles.variationRow}>
                  <TextInput
                    style={[styles.input, { flex: 1.4 }]}
                    placeholder="e.g. Large"
                    placeholderTextColor={colors.muted}
                    value={v.variation_name}
                    onChangeText={(val) => updateVariationRow(v.key, 'variation_name', val)}
                  />
                  <TextInput
                    style={[styles.input, { flex: 1 }]}
                    placeholder={`${currency}0`}
                    placeholderTextColor={colors.muted}
                    keyboardType="decimal-pad"
                    value={v.price}
                    onChangeText={(val) => updateVariationRow(v.key, 'price', val)}
                  />
                  <Pressable style={styles.removeVariationButton} onPress={() => removeVariationRow(v.key)} hitSlop={8}>
                    <Ionicons name="close" size={16} color={colors.danger} />
                  </Pressable>
                </View>
              ))}
              <Pressable style={styles.addVariationButton} onPress={addVariationRow}>
                <Ionicons name="add" size={16} color={colors.primary} />
                <Text style={styles.addVariationText}>Add Variation</Text>
              </Pressable>
            </View>
          ) : null}

          {formError ? <Text style={styles.formError}>{formError}</Text> : null}

          <Pressable
            style={({ pressed }) => [styles.saveButton, pressed && { opacity: 0.9 }]}
            onPress={save}
            disabled={saving}
          >
            {saving ? (
              <ActivityIndicator color="#fff" size="small" />
            ) : (
              <Text style={styles.saveButtonText}>{isCreate ? 'Add Item' : 'Save Changes'}</Text>
            )}
          </Pressable>
        </ScrollView>
        </KeyboardAvoidingView>
      </View>
    );
  }

  const available_ = item.is_available == 1;
  const hidden_ = item.is_hidden == 1;
  const heroImage = resolveImageUrl(item.item_image);

  return (
    <View style={styles.fill}>
      <Header
        title={item.item_name_en}
        onBack={() => navigation.goBack()}
        insets={insets}
        right={
          <Pressable style={styles.iconButton} onPress={startEdit} hitSlop={8}>
            <Ionicons name="pencil" size={18} color={colors.primary} />
          </Pressable>
        }
      />

      <ScrollView style={styles.fill} contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
        <View style={[styles.heroCard, shadow.sm]}>
          {heroImage ? (
            <Image source={{ uri: heroImage }} style={styles.heroImage} />
          ) : (
            <View style={styles.heroThumb}>
              <Ionicons name="fast-food-outline" size={30} color={colors.primary} />
            </View>
          )}
          <Text style={styles.heroName}>{item.item_name_en}</Text>
          <View style={styles.heroMetaRow}>
            {item.item_category ? (
              <View style={styles.categoryChip}>
                <Text style={styles.categoryText}>{item.item_category}</Text>
              </View>
            ) : null}
            <View style={[styles.availabilityPill, { backgroundColor: available_ ? colors.successBg : colors.dangerBg }]}>
              <View style={[styles.dot, { backgroundColor: available_ ? colors.success : colors.danger }]} />
              <Text style={[styles.availabilityPillText, { color: available_ ? colors.success : colors.danger }]}>
                {available_ ? 'Available' : 'Off'}
              </Text>
            </View>
            {hidden_ ? (
              <View style={[styles.availabilityPill, { backgroundColor: colors.border }]}>
                <Ionicons name="eye-off-outline" size={12} color={colors.inkSoft} />
                <Text style={[styles.availabilityPillText, { color: colors.inkSoft }]}>Hidden</Text>
              </View>
            ) : null}
          </View>
          <Text style={styles.heroPrice}>{currency}{item.base_price ?? 0}</Text>
        </View>

        <View style={[styles.card, shadow.sm]}>
          <Text style={styles.sectionTitle}>Details</Text>
          <Row label="Menu" value={item.menu_name} />
          <Row label="Subcategory" value={item.subcategory_name} />
          <Row label="Type" value={item.item_type} />
          <Row label="Prep time" value={item.preparation_time ? `${item.preparation_time} min` : null} />
          <Row label="Calories" value={item.calories ? `${item.calories} kcal` : null} />
        </View>

        {item.item_description_en ? (
          <View style={[styles.card, shadow.sm]}>
            <Text style={styles.sectionTitle}>Description</Text>
            <Text style={styles.description}>{item.item_description_en}</Text>
          </View>
        ) : null}

        {(item.variations || []).length > 0 ? (
          <View style={[styles.card, shadow.sm]}>
            <Text style={styles.sectionTitle}>Variations</Text>
            {item.variations.map((v, i) => (
              <Row key={v.id ?? i} label={v.variation_name} value={`${currency}${v.price}`} />
            ))}
          </View>
        ) : null}

        <Pressable
          style={({ pressed }) => [styles.hideButton, pressed && { opacity: 0.9 }]}
          onPress={toggleHidden}
          disabled={togglingHidden}
        >
          {togglingHidden ? (
            <ActivityIndicator color={colors.inkSoft} size="small" />
          ) : (
            <>
              <Ionicons name={hidden_ ? 'eye-outline' : 'eye-off-outline'} size={16} color={colors.inkSoft} />
              <Text style={styles.hideButtonText}>{hidden_ ? 'Unhide Item' : 'Hide from POS & Website'}</Text>
            </>
          )}
        </Pressable>

        <Pressable
          style={({ pressed }) => [styles.deleteButton, pressed && { opacity: 0.9 }]}
          onPress={confirmDelete}
          disabled={deleting}
        >
          {deleting ? (
            <ActivityIndicator color={colors.danger} size="small" />
          ) : (
            <>
              <Ionicons name="trash-outline" size={16} color={colors.danger} />
              <Text style={styles.deleteButtonText}>Delete Item</Text>
            </>
          )}
        </Pressable>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1, backgroundColor: colors.bg },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  iconButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.bg,
  },
  headerTitle: {
    flex: 1,
    fontFamily: font.semiBold,
    fontSize: 16,
    color: colors.ink,
  },
  content: {
    padding: spacing.xl,
    paddingBottom: 120,
    gap: spacing.md,
  },
  heroCard: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    alignItems: 'center',
  },
  heroThumb: {
    width: 64,
    height: 64,
    borderRadius: 20,
    backgroundColor: colors.primaryLight,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.sm,
  },
  heroImage: {
    width: 96,
    height: 96,
    borderRadius: 20,
    marginBottom: spacing.sm,
    backgroundColor: colors.bg,
  },
  heroName: {
    fontFamily: font.bold,
    fontSize: 18,
    color: colors.ink,
    textAlign: 'center',
  },
  heroMetaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.sm,
    flexWrap: 'wrap',
    justifyContent: 'center',
  },
  heroPrice: {
    fontFamily: font.bold,
    fontSize: 22,
    color: colors.primary,
    marginTop: spacing.md,
  },
  categoryChip: {
    backgroundColor: colors.bg,
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  categoryText: {
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.inkSoft,
  },
  availabilityPill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    borderRadius: radius.pill,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  availabilityPillText: {
    fontFamily: font.semiBold,
    fontSize: 11.5,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  sectionTitle: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.muted,
    marginBottom: spacing.sm,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 7,
    gap: spacing.md,
  },
  infoLabel: {
    fontFamily: font.regular,
    fontSize: 13,
    color: colors.muted,
  },
  infoValue: {
    flex: 1,
    textAlign: 'right',
    fontFamily: font.medium,
    fontSize: 13.5,
    color: colors.ink,
  },
  description: {
    fontFamily: font.regular,
    fontSize: 13.5,
    color: colors.inkSoft,
    lineHeight: 20,
  },
  hideButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: colors.border,
    borderRadius: radius.md,
    paddingVertical: 14,
    marginTop: spacing.sm,
  },
  hideButtonText: {
    color: colors.inkSoft,
    fontFamily: font.semiBold,
    fontSize: 14,
  },
  deleteButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: colors.dangerBg,
    borderRadius: radius.md,
    paddingVertical: 14,
  },
  deleteButtonText: {
    color: colors.danger,
    fontFamily: font.semiBold,
    fontSize: 14,
  },
  fieldLabel: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.inkSoft,
    marginBottom: spacing.xs,
    marginTop: spacing.sm,
  },
  labelRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  formatToggle: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  formatToggleText: {
    fontFamily: font.medium,
    fontSize: 11.5,
    color: colors.primary,
  },
  input: {
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontFamily: font.medium,
    fontSize: 14,
    color: colors.ink,
  },
  textArea: {
    minHeight: 80,
    textAlignVertical: 'top',
  },
  rowFields: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  imageRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  imagePreviewBox: {
    width: 64,
    height: 64,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  imagePreview: {
    width: '100%',
    height: '100%',
  },
  chooseImageButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    backgroundColor: colors.primaryLight,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 10,
  },
  chooseImageText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  availableRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: spacing.md,
  },
  variationsBox: {
    marginTop: spacing.sm,
    gap: spacing.sm,
  },
  variationRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  removeVariationButton: {
    width: 32,
    height: 32,
    borderRadius: 10,
    backgroundColor: colors.dangerBg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  addVariationButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    borderWidth: 1,
    borderColor: colors.primary,
    borderStyle: 'dashed',
    borderRadius: radius.md,
    paddingVertical: 10,
  },
  addVariationText: {
    fontFamily: font.semiBold,
    fontSize: 13,
    color: colors.primary,
  },
  formError: {
    fontFamily: font.medium,
    fontSize: 12.5,
    color: colors.danger,
    marginTop: spacing.md,
  },
  saveButton: {
    backgroundColor: colors.primary,
    borderRadius: radius.md,
    paddingVertical: 14,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: spacing.lg,
  },
  saveButtonText: {
    color: '#fff',
    fontFamily: font.semiBold,
    fontSize: 15,
  },
});
