# Synchronisation Cal Outlook
Compatible ITSM-NG V2
## Description
Le plugin **Synchronisation Cal Outlook** permet d'envoyer automatiquement une invitation de calendrier Outlook à la personne à laquelle une tâche est assignée. Celle-ci est envoyé par mail contenant le lien ainsi que la date de début et de fin.

## Installation
Pour fonctionner, il faut tout d'abord télécharger le plugin puis l'extraire.

```bash 
cd /tmp
git clone https://exemple.fr
tar -xf exemple.tar.gz
```

Puis copier dans le répertoire de plugins ITSM-NG et attribuer au bon profil le dossier
```bash
mv synchronisationcaloutlook /usr/share/itsm-ng/plugins/
chown -R www-data:www-data /usr/share/itsm-ng/plugins/synchronisationcaloutlook/
```

Et ensuite, sur ITSM-NG il faut aller sur ***Configuration > Plugins***, cliquer sur installer et activer le plugin **Synchronisation Cal Outlook**.

## Configuration
Sur la page de configuration des plugins, il faut sélectionner un **collecteur** ou la **configuration SMTP** du serveur.
Puis créer un gabarit de notification depuis le plugin en fonction du besoin.

## Fonctionnement
Le plugin va se déclencher automatiquement pour les tâches créées. Il ne va pas se lancer pour quelques exceptions, voici les critères :

- **Ticket non clos ou résolu**
- **Date de la tâche déjà passée**
- **case *"Ne pas envoyer d'invitation dans le calendrier"* est cochée**
- **tâche déjà faites**

Le plugin possède aussi une interface de debug sur son interface de configuration (*page* ***Configuration > Plugins > Synchronisation Cal Outlook***) nommée *Vérifier maintenant*.

Elle permet de voir les tâches qui n'ont pas reçues d'invitation, et de les relancer 1 par 1 ou de tout faire d'un seul coup.

Le plugin renvoi une invitation à la personne concerné si on change le bénéficiaire. Il annule l'ancienne invitation et envoi une modification d'heure si celle-ci est changée. Si la case ***Ne pas envoyer d'invitation dans le calendrier*** est cochée et que quelqu'un la décoche et enregistre, une invitation est envoyée.

## Sauvegarde configuration
Le plugin possède une fonctionnalitée **d'export** et **d'import**, celle-ci va exporter la configuration présente dans la base SQL dans plusieurs fichier sous /gabarit. Ce dossier peut-être sauvegardé et remis lors d'une mise à jour du plugin par exemple, cela permet de ne pas perdre la configuration html du gabarit. 

Pour le **réimporter** il faut remettre les fichiers dans gabarit, puis faire Importer, le plugin va utiliser le contenu des fichiers pour alimenter la table du plugin dans la base SQL.  
