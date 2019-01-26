# SymconLightify

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)

## 1. Funktionsumfang

Das Modul ermöglicht das Ansteuern von OSRAM Lightly Geräten über das interne Netzwerk.
Manche Geräte spezifischen Informationen (eg. Geräte Typ, Software version, ...) sowie das Anlegen und steuern von Szenen sind nur über die OSRAM Lightify Publi API möglich.

## 2. Voraussetzungen

 - IP-Symcon ab Version 5.0
 - OSRAM Lightify Home Gateway. Das PRO Gateway wird derzeit nicht unterstützt
 - Optional: Cloud-Zurgiff mit einem gültigen OSRAM Benutzer-Account
 - Optional: Symcon Connect. Bei Nutzung des Cloud-Zugriffs zwingend erforderlich

## 3. Installation

### 3.1. Laden des Moduls

Die Konsole von IP-Symcon öffnen. Im Objektbaum unter Kerninstanzen die Instanz **Modules** durch einen doppelten Mausklick öffnen.

In der _Modules_ Instanz rechts oben auf den Button **Hinzufügen** drücken.

In dem sich öffnenden Fenster folgende URL hinzufügen und mit _OK_ bestätigen:

`https://github.com/xconsult/SymconLightify`

 Den Zweig auf "beta-5.0" umstellen (Modul-Eintrag editieren, _Zweig_ auswählen).

Anschließend erscheint ein Eintrag für das Modul in der Liste der Instanz _Modules_

### 3.2. Einrichtung in IP-Symcon

In IP-Symcon unterhalb von _Splitter Instanzen_ die Funktion _Instanz hinzufügen_ (_CTRL+1_) auswählen und als Gerät _lightifyGateway_ auswählen.

Im Konfigurationsdialog können folgende Einstellungen vorgenommen werden:

| Einstellung                    |Beschreibung                                                                         |Hinweis                                           |
| :----------------------------  | :---------------------------------------------------------------------------------- | :----------------------------------------------- |
| **Aktiv**                      | Schnittstelle ein-/ausschalten                                                      |                                                  |
| **Verbindung**                 | Lokal oder Lokal und Cloud                                                          |                                                  |
| **Gateway IP**                 | IP-Adresse OSRAM Ligthtify Home Gateway                                             |                                                  |
| **Seriennummer**               | Ersten 11 Stellen der S/N vom Gateway                                               |                                                  |
| **Aktualisierung [s]**         | Zeitintervall für den Abgleich mit dem Gateway (Default: 10s)                       | Alle Werte unter 10s erhöhen die Last am Gateway (Minimum: 3s) |
| **Kategorien**                 | Auswahl der jeweiligen Kategorien in denen die Instanzen erstellt werden sollen). Sync (ja/nein) legt fest ob die Daten auch synchronisiert werden sollen |                                                  |
| **Registrieren**               | Registriert den Client und ermöglicht den Zugriff auf die OSRAM Lightify Public API | Symcon Connect erforderlich                      |
| **Erstellen \| Aktualisieren** | Die am Gateway registrierten Geräte, Gruppen und Szenen werden automatisch angelegt |                                                  |

**_Hinweis_**
- Es werden alle aktuellen Geräte unterstützt (Tuneable White, RGBW und Clear, Motion Sensor, Gartenspots, Stripes, Steckdosen), Gruppe und Szenen

## 4. Funktionsreferenz

### 4.1. Globale Funktionen

`bool OSR_SetValue(int $InstanceID, string $key, int $value)` --> depreciated
`bool OSR_WriteValue(int $InstanceID, string $key, int $value)`

| $key                  | $value       | Beschreibung                                         |
| :-------------------- | :----------: | :--------------------------------------------------- |
| **ALL_DEVICES**       | 1|0          | Alle Geräte schalten (1 = ein, 0 = aus)              |
| **SAVE**              | 1            | Speichert die aktuellen Werte permanent in der Lampe |
| **SCENE**             | 1-16         | Szene schalten                                       |
| **DEFAULT**           | 1            | Setzt auf Standwerte zurück                          |
| **SOFT_ON**           | 0-8000       | Fading beim Einschalten (in ms)                      |
| **SOFT_OFF**          | 0-8000       | Fading beim Ausschalten (in ms)                      |
| **RELAX**             | 1            | Vordefinierte Szene aus der Mobile App               |
| **ACTIVE**            | 1            | Vordefinierte Szene aus der Mobile App               |
| **PLANT_LIGHT**       | 1            | Vordefinierte Szene aus der Mobile App               |
| **LIGHTIFY_LOOP**     | 1            | Vordefinierte Szene aus der Mobile App               |
| **STATE**             | 1|0          | Gerät schalten (1 = ein, 0 = aus)                    |
| **COLOR**             | 255-16777215 | Lampen Farbe (HEX Wert als integer)                  |
| **COLOR_TEMPERATURE** | 2000-8000    | Lampen Farbtemperatur (anbhängig vom Lampentyp)      |
| **BRIGHTNESS**        | 0-100        | Lampen Helligkeit (0 schaltet die Lampe aus)         |
| **SATURATION**        | 0-100        | Lampen Farbsättigung                                 |

**_Rückgabewert_**
- Konnte der Befehl erfolgreich ausgeführt werden, liefert er als Ergebnis TRUE, andernfalls FALSE

**_Hinweis_**
- Ist die Lampe am Stromnnetz und ausgeschalten, wird sie beim Verändern eines Wertes automatisch eingeschaltet
- Die Variable Hue wird automatisch berechnet

## 5. Konfiguration

### 5.1. Gateway-Instanz

#### 5.1.1. Variablen

Es werden folgende Variablen angelegt:

| Name         | Typ     | Beschreibung                                             |
| :------------| :-----: | :------------------------------------------------------- |
| **SSID**     | string  | WLAN Name mit dem das Gateway verbunden ist              |
| **Port**     | integer | Kommunikations-Port über dem die Daten ausgelesen werden |
| **Firmware** | string  | Gateway Firmware Version                                 |

### 5.2. Geräte-Instanz

#### Auswahl

### Geräte

#### Properties

#### Variablen

### Statusvariablen

### Variablenprofile

Es werden folgende Variablenprofile angelegt:

| Name                 | Typ     | Werte       |
| :------------------- | :-----: | :---------: |
| **OSR.Hue**          | integer | 0°-360°     |
| **OSR.ColorTemp**    | integer | 2700K-6500K |
| **OSR.ColorTempExt** | integer | 2000K-8000K |
| **OSR.Intensity**    | integer | 0%-100%     |
| **OSR.Switch**       | boolean | true|false  |
| **OSR.Scene**        | integer | on          |
