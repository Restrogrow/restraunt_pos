import { Ionicons } from '@expo/vector-icons';
import { useNavigation } from '@react-navigation/native';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, Linking, Platform, StyleSheet, Text, View } from 'react-native';
import { WebView } from 'react-native-webview';
import ScreenHeader from '../components/ScreenHeader';
import { Button, HeaderIcon } from '../components/kit';
import { colors, font } from '../theme';

// A page of the browser panel (menus, settlements, invoices, a restaurant's own dashboard) inside
// the app. On Android the WebView shares the app's cookies, so it opens already signed in.
// params: { url, title, eyebrow, page } — `page` opens that section of superadmin/dashboard.php.
export default function WebPanelScreen({ route }) {
  const { url, title = 'Web panel', eyebrow = 'RestroGrow', page } = route.params || {};
  const navigation = useNavigation();
  const web = useRef(null);
  const [canBack, setCanBack] = useState(false);
  const [progress, setProgress] = useState(0);
  const [failed, setFailed] = useState(false);

  // Android Back steps back inside the page first
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (canBack) {
        web.current?.goBack();
        return true;
      }
      return false;
    });
    return () => sub.remove();
  }, [canBack]);

  const reload = useCallback(() => {
    setFailed(false);
    web.current?.reload();
  }, []);

  // the panel remembers its last section in localStorage; set it before the page script runs
  const preload = page ? `try{localStorage.setItem('saCurrentPage',${JSON.stringify(page)})}catch(e){}true;` : 'true;';

  if (Platform.OS === 'web') {
    return (
      <View style={{ flex: 1, backgroundColor: colors.bg }}>
        <ScreenHeader eyebrow={eyebrow} title={title} onBack={() => navigation.goBack()} />
        <View style={styles.center}>
          <Text style={styles.text}>This opens in a new browser tab.</Text>
          <Button title="Open" icon="open-outline" onPress={() => window.open(url, '_blank')} />
        </View>
      </View>
    );
  }

  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScreenHeader
        eyebrow={eyebrow}
        title={title}
        onBack={() => navigation.goBack()}
        right={
          <View style={{ flexDirection: 'row', gap: 8 }}>
            <HeaderIcon icon="refresh" label="Reload" onPress={reload} />
            <HeaderIcon icon="open-outline" label="Open in browser" onPress={() => Linking.openURL(url)} />
          </View>
        }
      />
      {progress > 0 && progress < 1 ? (
        <View style={styles.barTrack}>
          <View style={[styles.bar, { width: `${Math.round(progress * 100)}%` }]} />
        </View>
      ) : null}
      {failed ? (
        <View style={styles.center}>
          <Ionicons name="cloud-offline-outline" size={36} color={colors.danger} />
          <Text style={styles.text}>Couldn't open this page. Check the connection.</Text>
          <Button title="Try again" icon="refresh" onPress={reload} />
        </View>
      ) : null}
      <WebView
        ref={web}
        source={{ uri: url }}
        style={{ flex: 1, backgroundColor: colors.bg, display: failed ? 'none' : 'flex' }}
        sharedCookiesEnabled
        thirdPartyCookiesEnabled
        domStorageEnabled
        javaScriptEnabled
        setSupportMultipleWindows={false}
        injectedJavaScriptBeforeContentLoaded={preload}
        onNavigationStateChange={(s) => setCanBack(s.canGoBack)}
        onLoadProgress={(e) => setProgress(e.nativeEvent.progress)}
        onError={() => setFailed(true)}
        startInLoadingState
        renderLoading={() => (
          <View style={[StyleSheet.absoluteFill, styles.center]}>
            <ActivityIndicator size="large" color={colors.primary} />
          </View>
        )}
        // files the panel offers for download (CSV exports, PDFs) open in the phone's browser
        onFileDownload={({ nativeEvent }) => Linking.openURL(nativeEvent.downloadUrl)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 14, padding: 30, backgroundColor: colors.bg },
  text: { fontFamily: font.medium, fontSize: 14, color: colors.inkSoft, textAlign: 'center' },
  barTrack: { height: 3, backgroundColor: colors.border },
  bar: { height: 3, backgroundColor: colors.primary },
});
